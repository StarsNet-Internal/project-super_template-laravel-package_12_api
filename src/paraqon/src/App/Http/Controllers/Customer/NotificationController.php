<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Enums
use App\Enums\Status;

// Models
use Starsnet\Project\Paraqon\App\Models\Notification;
use App\Models\Order;
use App\Models\Store;
use Starsnet\Project\Paraqon\App\Models\AuctionRegistrationRequest;
use Starsnet\Project\Paraqon\App\Models\AuctionLot;
use Starsnet\Project\Paraqon\App\Models\Bid;
use Starsnet\Project\Paraqon\App\Models\Deposit;

class NotificationController extends Controller
{
    public function getAllNotifications(Request $request): Collection
    {
        $request->validate([
            'kind' => 'sometimes|in:system,direct',
            'is_read' => 'sometimes|in:true,false,1,0',
            'path' => 'sometimes|string|max:200',
        ]);
        $query = Notification::visibleToAccount((string) $this->account()->_id);
        if ($request->has('is_read')) $query->where('is_read', $request->boolean('is_read'));
        if ($request->filled('kind')) $query->ofKind($request->input('kind'));
        if ($request->filled('path')) $query->where('path', $request->input('path'));

        $notifications = $query->orderBy('created_at', 'desc')->get();
        $auctionTitles = $this->legacyAuctionTitles($notifications);

        // Do not expose staff identity or other recipients to the customer.
        return $notifications->map(function ($notification) use ($auctionTitles) {
            $data = array_merge($notification->only([
                '_id', 'subject', 'description', 'category', 'collection', 'document_id',
                'path', 'is_read', 'created_at', 'updated_at',
            ]), ['kind' => $notification->kind ?? 'system']);
            if ($data['kind'] !== 'direct') {
                $title = $auctionTitles[$notification->collection . ':' . $notification->document_id] ?? [];
                foreach (['en' => 'Auction', 'zh' => '拍賣', 'cn' => '拍卖'] as $language => $fallback) {
                    $replacement = is_string($title) ? ($title ?: $fallback) : (($title[$language] ?? null) ?: ($title['en'] ?? null) ?: $fallback);
                    if (is_array($data['subject']) && isset($data['subject'][$language])) {
                        $data['subject'][$language] = preg_replace_callback('/\{\{\s*auction\s*\}\}/', fn () => $replacement, $data['subject'][$language]);
                    } elseif (is_string($data['subject']) && $language === 'en') {
                        $data['subject'] = preg_replace_callback('/\{\{\s*auction\s*\}\}/', fn () => $replacement, $data['subject']);
                    }
                }
            }
            return $data;
        });
    }

    private function legacyAuctionTitles(Collection $notifications): array
    {
        // Read-only compatibility for old titles. Resolve in batches, not one query per row.
        $models = [
            'auction_registration_requests' => AuctionRegistrationRequest::class,
            'auction_lots' => AuctionLot::class,
            'bids' => Bid::class,
            'orders' => Order::class,
            'deposits' => Deposit::class,
        ];
        $legacy = $notifications->filter(function ($notification) use ($models) {
            return $notification->kind !== 'direct' && isset($models[$notification->collection]) &&
                preg_match('/^[a-f\d]{24}$/i', (string) $notification->document_id) &&
                preg_match('/\{\{\s*auction\s*\}\}/', json_encode($notification->subject));
        })->groupBy('collection');
        $sources = [];
        $lotIds = [];
        foreach ($legacy as $collection => $rows) {
            foreach ($models[$collection]::whereIn('_id', $rows->pluck('document_id')->unique()->all())
                ->get(['_id', 'store_id', 'auction_lot_id']) as $document) {
                $sources[$collection . ':' . $document->_id] = $document;
                if ($document->auction_lot_id) $lotIds[] = (string) $document->auction_lot_id;
            }
        }
        $lotIds = array_values(array_filter(array_unique($lotIds), fn ($id) => preg_match('/^[a-f\d]{24}$/i', $id)));
        $lots = $lotIds ? AuctionLot::whereIn('_id', $lotIds)->get(['_id', 'store_id'])->keyBy('_id') : collect();
        $storeIds = [];
        foreach ($sources as $key => $document) {
            $storeId = $document->store_id ?: optional($lots->get((string) $document->auction_lot_id))->store_id;
            if (preg_match('/^[a-f\d]{24}$/i', (string) $storeId)) $storeIds[$key] = (string) $storeId;
        }
        if (!$storeIds) return [];
        $stores = Store::whereIn('_id', array_values(array_unique($storeIds)))->get(['_id', 'title'])->keyBy('_id');
        $titles = [];
        foreach ($storeIds as $key => $storeId) $titles[$key] = optional($stores->get($storeId))->title ?? [];
        return $titles;
    }

    public function markNotificationsAsRead(Request $request): array
    {
        $account = $this->account();
        $request->validate(['id' => 'sometimes|string|regex:/^[a-fA-F0-9]{24}$/', 'path' => 'sometimes|string|max:200']);
        $query = Notification::visibleToAccount((string) $account->_id)
            ->where('is_read', false);

        if ($request->filled('id')) {
            $query->where('id', $request->input('id'));
        } elseif ($request->filled('path')) {
            $query->where('path', $request->input('path'));
        } else {
            abort(400, 'Either id or path parameter is required');
        }

        $readNotificationIDs = $query->pluck('id')->all();
        $updatedCount = $query->update(['is_read' => true]);

        $unreadNotificationCount = Notification::visibleToAccount((string) $account->_id)
            ->where('is_read', false)
            ->count();

        return [
            'message' => 'Notification updated is_read as true',
            'data' => [
                'account_id' => $account->_id,
                'read_notification_count' => $updatedCount,
                'read_notification_ids' => $readNotificationIDs,
                'unread_notification_count' => $unreadNotificationCount,
            ]
        ];
    }

    public function deleteNotification(Request $request): array
    {
        /** @var ?Notification $notification */
        $notification = Notification::find($request->route('id'));
        if (is_null($notification)) abort(404, 'Notification not found');

        $account = $this->account();
        if ($notification->account_id != $account->_id) abort(404, 'This Notification does not belong to this account_id');

        // Delete Notification
        $updatedCount = $notification->update([
            'status' => Status::DELETED->value,
            'deleted_at' => now()
        ]);

        // Get Notifications unread count
        $unreadNotificationCount = Notification::visibleToAccount((string) $account->_id)
            ->where('is_read', false)
            ->count();

        return [
            'message' => 'Notification deleted',
            '_id' => $notification->id,
            'data' => [
                'account_id' => $account->id,
                'read_notification_count' => $updatedCount,
                'read_notification_ids' => [$notification->id],
                'unread_notification_count' => $unreadNotificationCount,
            ]
        ];
    }
}
