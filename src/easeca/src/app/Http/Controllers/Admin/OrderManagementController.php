<?php

namespace StarsNet\Project\Easeca\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Carbon\Carbon;

// Models
use App\Models\Account;
use App\Models\Order;
use App\Models\ProductReview;

class OrderManagementController extends Controller
{
    public function getAllOrdersByStore(Request $request)
    {
        // Extract attributes from $request
        $statuses = (array) $request->input('current_status', []);

        $orders = Order::when($request->start_date, function ($query) use ($request) {
            return $query->whereBetween('created_at', [Carbon::parse($request->start_date), Carbon::parse($request->end_date)]);
        })
            ->whereIn('store_id', $request->store_id)
            ->when($statuses, function ($query, $statuses) {
                return $query->whereCurrentStatuses($statuses);
            })
            ->latest()
            ->get()
            ->makeHidden([
                'cashier_id',
                'payment_method',
                'transaction_method',
                'cart_items',
                'gift_items',
                'amount_received',
                'change',
                'delivery_info',
                'documents',
                'statuses',
                'store',
                'image',
            ]);

        $reviews = ProductReview::all()
            ->keyBy('order_id')
            ->makeHidden([
                'user',
                'product_title',
                'product_variant_title',
                'image',
            ])
            ->toArray();

        $accounts = Account::all()
            ->keyBy('user_id')
            ->toArray();

        foreach ($orders as $order) {
            if (array_key_exists($order->_id, $reviews)) {
                $review = $reviews[$order->_id];
                $user = [
                    'user' => [
                        'username' => $accounts[$review['user_id']]['username']
                    ]
                ];
                $order->product_reviews = [array_merge($review, $user)];
            } else {
                $order->product_reviews = [];
            }
        }
        return $orders;
    }

    public function updateDeliveryAddress(Request $request): array
    {
        /** @var ?Order $order */
        $order = Order::find($request->route('id'));
        if (is_null($order)) abort(404, 'Order not found');

        // Update Order
        Order::where('id', $request->route('id'))->update([
            'delivery_details.address' => $request->address,
        ]);

        return [
            'message' => 'Updated delivery address successfully',
        ];
    }
}
