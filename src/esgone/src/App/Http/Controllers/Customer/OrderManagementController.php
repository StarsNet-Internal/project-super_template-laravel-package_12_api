<?php

namespace Starsnet\Project\Esgone\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// Models
use App\Models\Alias;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Store;

class OrderManagementController extends Controller
{
    public function getAllOrdersByStore(Request $request): array
    {
        // Extract attributes from $request
        $statuses = (array) $request->input('current_status', []);
        $storeIds = array_filter((array) $request->store_id); // Remove empty values
        if (empty($storeIds)) return [];

        // Get Store(s)
        $stores = [];
        foreach ($storeIds as $storeId) {
            /** @var ?Store $store */
            $store = Store::find($storeId) ?? Store::find(Alias::getValue($storeId));
            if (!is_null($store)) $stores[] = $store;
        }
        if (count($stores) == 0) return [];

        // Get authenticated User information
        $customer = $this->customer();

        /** @var Collection $orders */
        $orders = Order::whereIn('store_id', collect($stores)->pluck('id')->all())
            ->where('customer_id', $customer->id)
            ->when($statuses, function ($query, $statuses) {
                return $query->whereCurrentStatuses($statuses);
            })
            ->get();

        return array_map(function ($order) {
            $cartItems = $order['cart_items'];
            foreach ($cartItems as $itemIndex => $item) {
                $productVariantId = $item['product_variant_id'];
                $variant = ProductVariant::find($productVariantId);

                $order['cart_items'][$itemIndex]['variant'] = $variant;
            }
            return $order;
        }, $orders->toArray());
    }

    public function getOrderDetailsAsCustomer(Request $request): array
    {
        /** @var ?Order $order */
        $order = Order::find($request->route('order_id'));
        if (is_null($order)) abort(404, 'Order not found');
        if (!$order->customer_id === $this->customer()->id) abort(403, 'Order does not belong to this Customer');

        // Get Checkout
        $order->checkout = $order->checkout()->latest()->first();

        // Append keys
        $order = $order->toArray();
        $cartItems = $order['cart_items'];
        foreach ($cartItems as $itemIndex => $item) {
            $productVariantId = $item['product_variant_id'];
            $variant = ProductVariant::find($productVariantId);
            $order['cart_items'][$itemIndex]['variant'] = $variant;
        }

        return $order;
    }
}
