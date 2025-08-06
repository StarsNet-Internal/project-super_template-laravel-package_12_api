<?php

namespace StarsNet\Project\Easeca\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// Enums
use App\Enums\Status;
use App\Enums\StoreType;

// Models
use App\Models\Store;

class OfflineStoreManagementController extends Controller
{
    public function getAllOfflineStores(Request $request)
    {
        // Extract attributes from $request
        $statuses = (array) $request->input('status', Status::defaultStatuses());

        return Store::whereType(StoreType::OFFLINE->value)
            ->statusesAllowed(Status::defaultStatuses(), $statuses)
            ->with([
                'warehouses' => function ($query) {
                    $query->statuses(Status::defaultStatuses());
                },
                'cashiers' => function ($query) {
                    $query->statuses(Status::defaultStatuses());
                },
            ])
            ->get();
    }

    public function deleteStores(Request $request)
    {
        $updatedCount = Store::whereIn((array) $request->input('ids'))
            ->where('is_system', false)
            ->update([
                'status' => Status::DELETED->value,
                'deleted_at' => now()
            ]);

        return [
            'message' => 'Deleted ' . $updatedCount . ' Store(s) successfully'
        ];
    }
}
