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

// Enums
use App\Enums\Status;

// Models
use Starsnet\Project\Paraqon\App\Models\Notification;

class NotificationController extends Controller
{
    private function requireStaff(): void
    {
        abort_unless($this->user()->is_staff && $this->account(), 403, 'Staff access required');
    }

    public function sendMessage(Request $request): array
    {
        $this->requireStaff();
        $data = $request->validate([
            'customer_ids' => 'required|array|min:1|max:100',
            'customer_ids.*' => 'required|string|distinct|regex:/^[a-fA-F0-9]{24}$/',
            'subject' => 'required|array:en,zh,cn',
            'subject.en' => 'required|string|max:300',
            'subject.zh' => 'nullable|string|max:300',
            'subject.cn' => 'nullable|string|max:300',
            'description' => 'required|array:en,zh,cn',
            'description.en' => 'required|string|max:20000',
            'description.zh' => 'nullable|string|max:20000',
            'description.cn' => 'nullable|string|max:20000',
        ]);
        $customers = Customer::with('account.user')->whereIn('_id', $data['customer_ids'])->get();
        abort_unless($customers->count() === count($data['customer_ids']), 422, 'Some customers do not exist');
        foreach ($customers as $customer) {
            abort_unless($customer->account && $customer->account->user &&
                !$customer->account->user->is_deleted, 422, 'A recipient account is unavailable');
        }
        $batchId = (string) Str::uuid();
        $staff = $this->account();
        $sentAt = now();
        DB::connection('mongodb')->transaction(function () use ($customers, $data, $batchId, $staff, $sentAt) {
            foreach ($customers as $customer) {
                Notification::create([
                    'type' => 'customer', 'kind' => 'direct',
                    'account_id' => (string) $customer->account->_id,
                    'customer_id' => (string) $customer->_id,
                    'batch_id' => $batchId,
                    // Each manual message has its own object key for existing badge deduplication.
                    'document_id' => $batchId, 'path' => 'contact',
                    'subject' => $data['subject'], 'description' => $data['description'],
                    'sent_by' => (string) $staff->_id, 'sent_by_name' => $staff->username,
                    'sent_at' => $sentAt, 'is_read' => false, 'status' => Status::ACTIVE->value,
                ]);
            }
        });
        return ['message' => 'Message sent successfully', 'batch_id' => $batchId, 'recipient_count' => $customers->count()];
    }

    public function getSentMessages(Request $request): Collection
    {
        $this->requireStaff();
        $request->validate(['archived' => 'sometimes|in:true,false,1,0']);
        $query = Notification::where('kind', 'direct')->where('type', 'customer')->whereNull('admin_deleted_at');
        if ($request->boolean('archived')) $query->whereNotNull('admin_archived_at');
        else $query->whereNull('admin_archived_at');

        return $query->orderBy('sent_at', 'desc')->get()->groupBy('batch_id')->map(function ($rows, $batchId) {
            $first = $rows->first();
            return [
                '_id' => $batchId, 'subject' => $first->subject, 'description' => $first->description,
                'customer_ids' => $rows->pluck('customer_id')->unique()->values(),
                'sent_by' => $first->sent_by, 'sent_by_name' => $first->sent_by_name,
                'sent_at' => $first->sent_at, 'admin_archived_at' => $first->admin_archived_at,
            ];
        })->values();
    }

    public function manageSentMessage(Request $request): array
    {
        $this->requireStaff();
        $data = $request->validate(['action' => 'required|in:archive,restore,delete']);
        $query = Notification::where('kind', 'direct')->where('type', 'customer')
            ->where('batch_id', $request->route('batch_id'))->whereNull('admin_deleted_at');
        abort_unless((clone $query)->exists(), 404, 'Message not found');
        $field = $data['action'] === 'delete' ? 'admin_deleted_at' : 'admin_archived_at';
        // Staff history management must not remove recipients' inbox records.
        $query->update([$field => $data['action'] === 'restore' ? null : now()]);
        return ['message' => 'Message history updated'];
    }

    public function createNotification(Request $request): array
    {
        $this->requireStaff();
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
