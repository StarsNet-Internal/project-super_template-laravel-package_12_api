<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Enums
use App\Enums\CheckoutType;
use App\Enums\ShipmentDeliveryStatus;
use App\Enums\StoreType;
use App\Http\Starsnet\PinkiePay;

// Models
use App\Models\Order;
use App\Models\Store;

class OrderController extends Controller
{
    public function getOrdersByStoreID(Request $request): Collection
    {
        $orders = Order::where('store_id', $request->route('store_id'))
            ->where('customer_id',  $this->customer()->id)
            ->with(['store'])
            ->get();

        foreach ($orders as $order) {
            $order->checkout = $order->checkout()->latest()->first();

            // Handle image directly from cart_items
            $order->image = null;
            if (!empty($order->cart_items) && count($order->cart_items) > 0) {
                foreach ($order->cart_items as $item) {
                    if (!empty($item['image'])) {
                        $order->image = $item['image'];
                        break; // Stop after finding first image
                    }
                }
            }
        }

        return $orders;
    }

    public function uploadPaymentProofAsCustomer(Request $request): array
    {
        /** @var ?Order $order */
        $order = Order::find($request->route('order_id'));
        if (is_null($order)) abort(404, 'Order not found');

        $customer = $this->customer();
        if ($order->customer_id != $customer->id) abort(401, 'Order does not belong to this Customer');

        // Get Checkout
        /** @var Checkout $checkout */
        $checkout = $order->checkout()->latest()->first();
        if ($checkout->payment_method != CheckoutType::OFFLINE->value) abort(403, 'Order does not accept OFFLINE payment');

        $checkout->update([
            'offline' => [
                'image' => $request->image,
                'uploaded_at' => now(),
                'api_response' => null
            ]
        ]);

        // Update Order
        if ($order->current_status !== ShipmentDeliveryStatus::PENDING->value) {
            $order->updateStatus(ShipmentDeliveryStatus::PENDING->value);
        }

        return ['message' => 'Uploaded image successfully'];
    }

    public function payPendingOrderByOnlineMethod(Request $request)
    {
        /** @var ?Order $order */
        $order = Order::find($request->route('order_id'));
        if (is_null($order)) abort(404, 'Order not found');
        if ($this->customer()->id !== $order->customer_id) abort(403, 'Customer do not own this order');

        // Create paymentToken
        $amount = $order->getTotalPrice();
        $url = $this->serviceApiUrl . $this->createTokenPath;

        $checkout = $order->checkout()->latest()->first();
        $checkout->update([
            'online' => [
                'transaction_id' => null,
                'api_response' => null
            ]
        ]);

        $pinkiePay = new PinkiePay($order, $request->success_url, $request->cancel_url);
        $data = $pinkiePay->createPaymentToken();
        $returnUrl = $data['shortened_url'];

        return [
            'message' => 'Generated new payment url successfully',
            'return_url' => $returnUrl ?? null,
            'order_id' => $order->id
        ];
    }

    public function getAllOfflineOrders(): Collection
    {
        /** @var array $offlineStoreIDs */
        $offlineStoreIDs = Store::where('type', StoreType::OFFLINE->value)->pluck('id')->all();

        // Get Order(s)
        /** @var Collection $orders */
        $orders = Order::whereIn('store_id', $offlineStoreIDs)
            ->where('customer_id', $this->customer()->id)
            ->with(['store'])
            ->get();

        foreach ($orders as $order) {
            $order->checkout = $order->checkout()->latest()->first();

            // Handle image directly from cart_items
            $order->image = null;
            if (!empty($order->cart_items) && count($order->cart_items) > 0) {
                foreach ($order->cart_items as $item) {
                    if (!empty($item['image'])) {
                        $order->image = $item['image'];
                        break; // Stop after finding first image
                    }
                }
            }
        }

        return $orders;
    }

    public function updateOrderDetails(Request $request): array
    {
        /** @var ?Order $order */
        $order = Order::find($request->route('order_id'));
        if (is_null($order)) abort(404, 'Order not found');
        if ($this->customer()->id !== $order->customer_id) abort(403, 'Customer do not own this order');

        // Update Order
        $order->update($request->all());

        return ['message' => 'Updated Order Successfully'];
    }
}
