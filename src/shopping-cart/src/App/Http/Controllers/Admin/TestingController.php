<?php

namespace Starsnet\Project\ShoppingCart\App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TestingController extends Controller
{
    public function healthCheck(Request $request)
    {
        return response()->json([
            'message' => 'Healthy'
        ], 200);
    }
}
