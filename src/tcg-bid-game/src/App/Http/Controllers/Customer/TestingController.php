<?php

namespace Starsnet\Project\TcgBidGame\App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;

class TestingController extends Controller
{
    public function healthCheck()
    {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now(),
        ], 200);
    }
}
