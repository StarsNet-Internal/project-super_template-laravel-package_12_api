<?php

namespace Starsnet\Project\WhiskyWhiskers\App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Enums\Status;
use App\Enums\StoreType;
use Carbon\Carbon;

class TestingController extends Controller
{
    public function healthCheck()
    {
        // $now = now();
        // $upcomingStores = Store::where(
        //     'type',
        //     StoreType::OFFLINE
        // )
        //     ->orderBy('start_datetime')
        //     ->get();

        // $nearestUpcomingStore = null;
        // foreach ($upcomingStores as $store) {
        //     $startTime = $store->start_datetime;
        //     $startTime = Carbon::parse($startTime);
        //     if ($now < $startTime) {
        //         $nearestUpcomingStore = $store;
        //         break;
        //     }
        // }
        // return $nearestUpcomingStore;

        return response()->json([
            'message' => 'OK from package/whisky-whiskers'
        ], 200);
    }
}
