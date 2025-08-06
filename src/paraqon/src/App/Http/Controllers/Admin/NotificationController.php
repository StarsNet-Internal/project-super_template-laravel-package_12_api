<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Arr;

// Enums
use App\Enums\Status;

// Models
use Starsnet\Project\Paraqon\App\Models\Notification;

class NotificationController extends Controller
{
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
