<?php

namespace Starsnet\Project\Rmhc\App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Order;

class TestingController extends Controller
{
    public function healthCheck()
    {
        return [
            'url' => url()->current(),
            'date' => now()->format('Y-m-d'),
            'date_time' => now()->format('Y-m-d H:i:s'),
            'mysql_database' => config('database.connections.mysql.database'),
            'mongodb_database' => config('database.connections.mongodb.database'),
        ];
    }
}
