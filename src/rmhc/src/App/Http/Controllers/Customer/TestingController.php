<?php

namespace Starsnet\Project\Rmhc\App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Order;

class TestingController extends Controller
{
    public function healthCheck()
    {
        return response()->json([
            'message' => 'OK from package/rmhc2'
        ], 200);
    }
}
