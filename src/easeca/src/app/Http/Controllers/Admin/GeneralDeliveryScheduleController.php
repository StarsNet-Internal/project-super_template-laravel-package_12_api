<?php

namespace StarsNet\Project\Easeca\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// Models
use App\Models\Configuration;

class GeneralDeliveryScheduleController extends Controller
{
    public function getGeneralDeliverySchedule()
    {
        return Configuration::where('slug', 'general-delivery-schedule')
            ->latest()
            ->first();
    }

    public function updateGeneralDeliverySchedule(Request $request)
    {
        $schedule = Configuration::where('slug', 'general-delivery-schedule')
            ->latest()
            ->first();

        $schedule->update($request->all());

        return [
            'message' => 'Updated OrderCutOffSchedule successfully'
        ];
    }
}
