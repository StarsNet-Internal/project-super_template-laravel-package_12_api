<?php

namespace Starsnet\Project\WhiskyWhiskers\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// Services 
use App\Http\Starsnet\PinkiePay;

// Models
use App\Models\Order;

class OrderController extends Controller
{
    public function payPendingOrderByOnlineMethod(Request $request)
    {
        $order = Order::find($request->route('order_id'));
        if (is_null($order)) abort(404, 'Order not found');
        if ($order->customer_id != $this->customer()->id) abort(404, 'This Order does not belong to this user');

        $checkout = $order->checkout()->latest()->first();

        $pinkiePay = new PinkiePay($order, $request->success_url, $request->cancel_url);
        $data = $pinkiePay->createPaymentToken();
        $returnUrl = $data['shortened_url'];

        return [
            'message' => 'Generated new payment url successfully',
            'return_url' => $returnUrl ?? null,
            'order_id' => $order->_id
        ];
    }
}
