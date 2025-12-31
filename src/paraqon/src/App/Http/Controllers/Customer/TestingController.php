<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TestingController extends Controller
{
    public function healthCheck(Request $request)
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
