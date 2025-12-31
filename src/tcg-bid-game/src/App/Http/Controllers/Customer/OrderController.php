<?php

namespace Starsnet\Project\TcgBidGame\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// Models
use Starsnet\Project\TcgBidGame\App\Models\Order;
use Starsnet\Project\TcgBidGame\App\Models\Product;
use Starsnet\Project\TcgBidGame\App\Models\GameUser;

class OrderController extends Controller
{
    public function getAllOrders(Request $request)
    {
        $customer = $this->customer();
        $gameUser = GameUser::where('customer_id', $customer->id)->first();

        if (!$gameUser) {
            return collect([]);
        }

        $status = $request->input('status');

        $query = Order::byCustomer($gameUser->_id);

        if ($status) {
            $query->byStatus($status);
        }

        $orders = $query->orderBy('created_at', 'desc')
            ->get();

        return $orders;
    }

    public function getOrderById(Request $request)
    {
        $orderId = $request->route('order_id');
        $order = Order::find($orderId);

        if (!$order) {
            abort(404, 'Order not found');
        }

        // Verify ownership
        $customer = $this->customer();
        $gameUser = GameUser::where('customer_id', $customer->id)->first();

        if (!$gameUser || $order->customer_id !== $gameUser->_id) {
            abort(403, 'Order does not belong to this customer');
        }

        return $order;
    }

    public function createOrder(Request $request)
    {
        $productId = $request->input('product_id');

        if (!$productId) {
            abort(400, 'product_id is required');
        }

        $product = Product::find($productId);
        if (!$product) {
            abort(404, 'Product not found');
        }

        if (!$product->is_active) {
            abort(400, 'Product is not active');
        }

        if (!$product->isInStock()) {
            abort(400, 'Product out of stock');
        }

        // Get or create GameUser
        $customer = $this->customer();
        $gameUser = GameUser::where('customer_id', $customer->id)->first();

        if (!$gameUser) {
            $gameUser = GameUser::create([
                'customer_id' => $customer->id,
                'max_energy' => 20,
                'energy_recovery_interval_hours' => 1,
                'energy_recovery_amount' => 1,
            ]);
        }

        // Check if user has enough coins
        if (!$gameUser->hasEnoughCoins($product->price)) {
            abort(400, 'Insufficient coins');
        }

        // Create order
        $order = Order::create([
            'order_number' => Order::generateOrderNumber(),
            'customer_id' => $gameUser->_id,
            'status' => 'processing',
            'total_amount' => $product->price,
        ]);

        $order->associateCustomer($gameUser);
        $order->associateProduct($product);

        // Deduct coins via transaction
        $transaction = $gameUser->deductCoins(
            $product->price,
            'product_purchase',
            $order->_id,
            'order',
            [
                'zh' => "購買{$product->name['zh']}",
                'en' => "Purchased {$product->name['en']}",
                'cn' => "购买{$product->name['cn']}",
            ]
        );

        if (!$transaction) {
            abort(400, 'Failed to process payment');
        }

        // Decrease stock
        $product->decreaseStock(1);

        return response()->json($order, 201);
    }
}
