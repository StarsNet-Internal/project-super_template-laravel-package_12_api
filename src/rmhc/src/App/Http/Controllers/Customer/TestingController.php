<?php

namespace Starsnet\Project\Rmhc\App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Order;

class TestingController extends Controller
{
    public function healthCheck()
    {
        $order = Order::find('6873c3a75be4567eb00c1688');
        $duplicatedOrder = $order->replicate();
        $duplicatedOrder->save();
        return $duplicatedOrder;
        return response()->json([
            'message' => 'OK from package/rmhc2'
        ], 200);
    }
}
