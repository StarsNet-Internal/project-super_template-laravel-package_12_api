<?php

namespace StarsNet\Project\Easeca\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

// Enums
use App\Enums\Status;

// Models
use App\Models\Store;
use StarsNet\Project\Easeca\App\Models\OrderCutOffSchedule;

class OrderCutOffScheduleController extends Controller
{
    public function getAllOrderCutOffSchedule(Request $request): Collection
    {
        $statuses = (array) $request->input('status', Status::defaultStatuses());

        return OrderCutOffSchedule::whereHas('store', function ($query) use ($statuses) {
            $query->statusesAllowed(Status::defaultStatuses(), $statuses);
        })
            ->get();
    }

    public function updateOrderCutOffSchedule(Request $request): array
    {
        $schedule = OrderCutOffSchedule::where('store_id', $request->route('store_id'))->latest()->first();
        if (is_null($schedule)) abort(404, 'Schedule not found');

        $schedule->update($request->all());

        return ['message' => 'Updated OrderCutOffSchedule successfully'];
    }

    public function updateOrderCutOffSchedules(Request $request)
    {
        foreach ($request->items as $item) {
            $schedule = OrderCutOffSchedule::where('store_id', $item['store_id'])->first();
            $updateFields = Arr::except($item, ['store_id']);

            if (is_null($schedule)) {
                $store = Store::find($item['store_id']);
                if (is_null($store)) continue;

                /** @var OrderCutOffSchedule $schedule */
                $schedule = OrderCutOffSchedule::create($updateFields);
                $schedule->associateStore($store);
            } else {
                $schedule->update($updateFields);
            }
        }

        return ['message' => 'Updated OrderCutOffSchedule successfully'];
    }
}
