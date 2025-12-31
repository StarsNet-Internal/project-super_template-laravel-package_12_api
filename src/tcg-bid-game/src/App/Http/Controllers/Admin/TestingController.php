<?php

namespace Starsnet\Project\TcgBidGame\App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

class TestingController extends Controller
{
    public function healthCheck()
    {
        return response()->json([
            'message' => 'OK from package/tcg-bid-game'
        ], 200);
    }
}
