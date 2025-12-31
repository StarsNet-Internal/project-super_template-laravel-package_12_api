<?php

namespace Starsnet\Project\Rmhc\App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

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
