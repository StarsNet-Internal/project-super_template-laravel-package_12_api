<?php

namespace StarsNet\Project\Easeca\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;

class ScheduleController extends Controller
{
    public function getSchedule()
    {
        try {
            $account = $this->account();

            if ($account['store_id'] != null) {
                $url = 'https://timetable.easeca.tinkleex.com/customer/schedules?store_id=' . $account->store_id;
            } else {
                $url = 'https://timetable.easeca.tinkleex.com/customer/schedules';
            }
            $response = Http::get($url);
            return json_decode($response->getBody()->getContents(), true);
        } catch (\Throwable $th) {
            return [];
        }
    }
}
