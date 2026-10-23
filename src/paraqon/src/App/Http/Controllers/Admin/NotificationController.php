<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Enums\LoginType;

// Enums
use App\Enums\Status;

// Models
use Starsnet\Project\Paraqon\App\Models\Notification;

class NotificationController extends Controller
{
    public function sendMessage(Request $request): array
    {
        $request->mergeIfMissing(['recipient_type' => 'selected']);
        $data = $request->validate([
            'recipient_type' => 'required|in:selected,all,vault_seller,vault_buyer_vip',
            'customer_ids' => 'exclude_unless:recipient_type,selected|required|array|min:1',
            'customer_ids.*' => 'exclude_unless:recipient_type,selected|required|string|distinct|regex:/^[a-fA-F0-9]{24}$/',
            'subject' => 'required|array:en,zh,cn',
            'subject.en' => 'required|string|max:300',
            'subject.zh' => 'nullable|string|max:300',
            'subject.cn' => 'nullable|string|max:300',
            'description' => 'required|array:en,zh,cn',
            'description.en' => 'required|string|max:20000',
            'description.zh' => 'nullable|string|max:20000',
            'description.cn' => 'nullable|string|max:20000',
        ]);
        // Match the existing customer picker: registered, non-deleted customer accounts.
        $query = Customer::with('account.user')->whereHas('account', function ($account) {
            $account->whereHas('user', function ($user) {
                $user->where('type', '!=', LoginType::TEMP->value)->where('is_deleted', false);
            });
        });
        if ($data['recipient_type'] === 'selected') {
            $query->whereIn('_id', $data['customer_ids']);
        } elseif ($data['recipient_type'] !== 'all') {
            $slugs = ['vault_seller' => 'the-vault-seller-basic', 'vault_buyer_vip' => 'the-vault-buyer-vip'];
            $groupIds = CustomerGroup::where('slug', $slugs[$data['recipient_type']])
                ->where('item_type', 'Customer')->statusActive()->get()->pluck('_id')->all();
            abort_if(!$groupIds, 422, 'The selected customer group is unavailable');
            $query->whereIn('customer_group_ids', $groupIds);
        }
        $customers = $query->get();
        if ($data['recipient_type'] === 'selected') {
            abort_unless($customers->count() === count($data['customer_ids']), 422, 'Some recipient accounts are unavailable');
        }
        $customers = $customers->unique(fn ($customer) => (string) $customer->account->_id)->values();
        abort_if($customers->isEmpty(), 422, 'There are no eligible customers in this recipient selection');
        $batchId = (string) Str::uuid();
        $staff = $this->account();
        abort_unless($staff, 401, 'A logged-in account is required');
        $sentAt = now();
        DB::connection('mongodb')->transaction(function () use ($customers, $data, $batchId, $staff, $sentAt) {
            foreach ($customers->chunk(500) as $chunk) {
                $documents = $chunk->map(function ($customer) use ($data, $batchId, $staff, $sentAt) {
                    return (new Notification([
                        'type' => 'customer', 'kind' => 'direct',
                        'recipient_type' => $data['recipient_type'],
                        'account_id' => (string) $customer->account->_id,
                        'customer_id' => (string) $customer->_id,
                        'batch_id' => $batchId,
                        // Each manual message has its own object key for existing badge deduplication.
                        'document_id' => $batchId, 'path' => 'contact',
                        'subject' => $data['subject'], 'description' => $data['description'],
                        'sent_by' => (string) $staff->_id, 'sent_by_name' => $staff->username,
                        'sent_at' => $sentAt, 'is_read' => false, 'status' => Status::ACTIVE->value,
                        'created_at' => $sentAt, 'updated_at' => $sentAt,
                    ]))->getAttributes();
                })->values()->all();
                Notification::insert($documents);
            }
        });
        return ['message' => 'Message sent successfully', 'batch_id' => $batchId, 'recipient_count' => $customers->count()];
    }

    public function getSentMessages(Request $request): Collection
    {
        $request->validate(['archive_status' => 'sometimes|in:all,archived']);
        $query = Notification::where('kind', 'direct')->where('type', 'customer')->whereNull('admin_deleted_at');
        if ($request->input('archive_status') === 'archived') $query->whereNotNull('admin_archived_at');

        return $query->orderBy('sent_at', 'desc')->get()->groupBy('batch_id')->map(function ($rows, $batchId) {
            $first = $rows->first();
            return [
                '_id' => $batchId, 'subject' => $first->subject, 'description' => $first->description,
                'recipient_type' => $first->recipient_type ?? 'selected',
                'customer_ids' => $rows->pluck('customer_id')->unique()->values(),
                'sent_by' => $first->sent_by, 'sent_by_name' => $first->sent_by_name,
                'sent_at' => $first->sent_at, 'admin_archived_at' => $first->admin_archived_at,
            ];
        })->values();
    }

    public function manageSentMessage(Request $request): array
    {
        $data = $request->validate(['action' => 'required|in:archive,restore,delete']);
        $query = Notification::where('kind', 'direct')->where('type', 'customer')
            ->where('batch_id', $request->route('batch_id'))->whereNull('admin_deleted_at');
        abort_unless((clone $query)->exists(), 404, 'Message not found');
        if ($data['action'] !== 'delete') {
            // Archiving only organizes admin history; customer visibility and read state stay unchanged.
            $query->update(['admin_archived_at' => $data['action'] === 'archive' ? now() : null]);
            return ['message' => $data['action'] === 'archive' ? 'Message archived successfully' : 'Message restored successfully'];
        }
        $deletedAt = now();
        // Delete the whole send batch from admin history and every recipient's inbox.
        $query->update([
            'admin_deleted_at' => $deletedAt,
            'status' => Status::DELETED->value,
            'deleted_at' => $deletedAt,
        ]);
        return ['message' => 'Message deleted successfully'];
    }

    public function createNotification(Request $request): array
    {
        abort_if($request->input('kind') === 'direct', 422, 'Use the message send endpoint');
        // Exclude pagination/sorting params before filtering
        $notification = Notification::create($request->all());

        return [
            'message' => 'Notification created successfully',
            '_id' => $notification->id
        ];
    }

    public function getAllNotifications(Request $request): Collection
    {
        // Exclude pagination/sorting params before filtering
        $filterParams = Arr::except($request->query(), ['per_page', 'page', 'sort_by', 'sort_order']);

        $query = Notification::where('type', 'staff')->where('status', '!=', Status::DELETED->value);

        foreach ($filterParams as $key => $value) {
            $query->where($key, filter_var($value, FILTER_VALIDATE_BOOLEAN));
        }

        return $query->get();
    }

    public function markNotificationsAsRead(Request $request): array
    {
        $query = Notification::where('type', 'staff')
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

        $unreadNotificationCount = Notification::where('type', 'staff')
            ->where('is_read', false)
            ->count();

        return [
            'message' => 'Notifications marked as read',
            'data' => [
                'account_id' => $this->account()->id,
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
        if ($notification->type != 'staff') abort(404, 'This Notification does not belong to a staff');

        // Delete Notification
        $updatedCount = $notification->update([
            'status' => Status::ACTIVE->value,
            'deleted_at' => now()
        ]);

        // Get Notifications unread count
        $unreadNotificationCount = Notification::where('type', 'staff')
            ->where('is_read', false)
            ->count();

        return [
            'message' => 'Notification deleted',
            '_id' => $notification->id,
            'data' => [
                'account_id' => $this->account()->id,
                'read_notification_count' => $updatedCount,
                'read_notification_ids' => [$notification->id],
                'unread_notification_count' => $unreadNotificationCount,
            ]
        ];
    }
}
