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

        // Do not expose staff identity or other recipients to the customer.
        return $query->orderBy('created_at', 'desc')->get()->map(function ($notification) {
            return array_merge($notification->only([
                '_id', 'subject', 'description', 'category', 'collection', 'document_id',
                'path', 'is_read', 'created_at', 'updated_at',
            ]), ['kind' => $notification->kind ?? 'system']);
        });
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
