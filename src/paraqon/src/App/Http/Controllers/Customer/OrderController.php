<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

// Enums
use App\Enums\CheckoutApprovalStatus;
use App\Enums\CheckoutType;
use App\Enums\ShipmentDeliveryStatus;
use App\Enums\StoreType;

// Utils
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
        $checkout = $order->checkout()->latest()->first();
        $checkout->update([
            'online' => [
                'transaction_id' => null,
                'api_response' => null
            ]
        ]);

        $pinkiePay = new PinkiePay($order, $request->success_url, $request->cancel_url);
        $data = $pinkiePay->createPaymentToken();

        return [
            'message' => 'Generated new payment url successfully',
            'return_url' => $data['shortened_url'] ?? null,
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

    public function getAllOrdersByStoreType(Request $request): Collection
    {
        /** @var array $storeIDs */
        $storeIDs = Store::where('type', $request->store_type)->pluck('id')->all();

        if (count($storeIDs) === 0) {
            abort(404, 'No stores found for the specified store type');
        }

        // Get Order(s)
        /** @var Collection $orders */
        $orders = Order::whereIn('store_id', $storeIDs)
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

        $order->update($request->all());
        return ['message' => 'Updated Order Successfully'];
    }

    public function cancelOrderPayment(Request $request)
    {
        /** @var ?Order $order */
        $order = Order::find($request->route('order_id'));
        if (is_null($order)) abort(404, 'Order not found');
        if ($order->customer_id !== $this->customer()->id) abort(403, 'Order does not belong to you');
        if (is_null($order->scheduled_payment_at)) abort(400, 'Order does not have scheduled_payment_at');
        if (now()->gt($order->scheduled_payment_at)) abort(403, 'The scheduled payment time has already passed');

        /** @var ?Checkout $checkout */
        $checkout = $order->checkout()->latest()->first();
        $paymentIntentID = $checkout->online['payment_intent_id'];

        $url = env('PARAQON_STRIPE_BASE_URL', 'https://payment.paraqon.starsnet.hk') . '/payment-intents/' . $paymentIntentID . '/cancel';
        $response = Http::post($url);

        if ($response->status() === 200) {
            $order->updateStatus(ShipmentDeliveryStatus::CANCELLED->values);
            $checkout->updateApprovalStatus(CheckoutApprovalStatus::REJECTED->values);
            return ['message' => 'Update Order status as cancelled'];
        }

        return response()->json(['message' => 'Unable to cancel payment from Stripe, paymentIntent might have been closed']);
    }
}
