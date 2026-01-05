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
            abort(404, json_encode([
                'en' => 'Order not found',
                'zh' => '找不到訂單',
                'cn' => '找不到订单',
            ]));
        }

        // Verify ownership
        $customer = $this->customer();
        $gameUser = GameUser::where('customer_id', $customer->id)->first();

        if (!$gameUser || $order->customer_id !== $gameUser->_id) {
            abort(403, json_encode([
                'en' => 'Order does not belong to this customer',
                'zh' => '訂單不屬於此客戶',
                'cn' => '订单不属于此客户',
            ]));
        }

        return $order;
    }

    public function createOrder(Request $request)
    {
        $productId = $request->input('product_id');

        if (!$productId) {
            abort(400, json_encode([
                'en' => 'product_id is required',
                'zh' => '需要產品ID',
                'cn' => '需要产品ID',
            ]));
        }

        $product = Product::find($productId);
        if (!$product) {
            abort(404, json_encode([
                'en' => 'Product not found',
                'zh' => '找不到產品',
                'cn' => '找不到产品',
            ]));
        }

        if (!$product->is_active) {
            abort(400, json_encode([
                'en' => 'Product is not active',
                'zh' => '產品未啟用',
                'cn' => '产品未启用',
            ]));
        }

        if (!$product->isInStock()) {
            abort(400, json_encode([
                'en' => 'Product out of stock',
                'zh' => '產品缺貨',
                'cn' => '产品缺货',
            ]));
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
            abort(400, json_encode([
                'en' => 'Insufficient coins',
                'zh' => '金幣不足',
                'cn' => '金币不足',
            ]));
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
            abort(400, json_encode([
                'en' => 'Failed to process payment',
                'zh' => '處理付款失敗',
                'cn' => '处理付款失败',
            ]));
        }

        // Decrease stock
        $product->decreaseStock(1);

        return response()->json($order, 201);
    }
}
