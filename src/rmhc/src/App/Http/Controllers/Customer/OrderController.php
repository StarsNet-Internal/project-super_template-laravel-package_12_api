<?php

namespace Starsnet\Project\Rmhc\App\Http\Controllers\Customer;

// Laravel built-in

use App\Enums\CheckoutType;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Http\Request;

// Enums
use App\Enums\OrderPaymentMethod;
use App\Enums\ShipmentDeliveryStatus;
// Models
use App\Models\Alias;
use App\Models\Checkout;
use App\Models\Order;
use App\Models\Store;
use Starsnet\Project\Rmhc\App\Models\BatchPayment;

class OrderController extends Controller
{
    public function createDonationOrder(Request $request)
    {
        /** @var ?Store $store */
        $store = Store::find($request->store_id)
            ?? Store::find(Alias::getValue($request->store_id));
        if (is_null($store)) abort(404, 'Store not found');

        $amount = $request->amount;
        $displayAmount = number_format($amount, 2, '.', '');

        // Create cartItem
        $cartItem = [
            'product_id' => null,
            'product_variant_id' => null,
            'qty' => 1,
            'is_checkout' => true,
            'is_refundable' => false,
            'local_discount_type' => null,
            'global_discount' => null,
            'product_title' => '0.00',
            'product_variant_title' => '0.00',
            'image' => null,
            'sku' => null,
            'barcode' => null,
            'original_price_per_unit' => $displayAmount,
            'discounted_price_per_unit' => $displayAmount,
            'original_subtotal_price' => $displayAmount,
            'subtotal_price' => $displayAmount,
            'original_point_per_unit' => '0.00',
            'discounted_point_per_unit' => '0.00',
            'original_subtotal_point' => '0.00',
            'subtotal_point' => '0.00',
            'created_at' => now(),
            'updated_at' => now()
        ];

        // Create Order
        $orderAttributes = [
            'customer_id' => $this->customer()->id,
            'store_id' => $store->id,
            'is_paid' => false,
            'is_system' => true,
            'payment_method' => OrderPaymentMethod::OFFLINE->value,
            'cart_items' => [$cartItem],
            'gift_items' => [],
            'discounts' => [],
            'calculations' => [
                'currency' => 'HKD',
                'price' => [
                    'subtotal' => $displayAmount,
                    'total' => $displayAmount,
                ],
                'price_discount' => [
                    'local' =>  '0.00',
                    'global' =>  '0.00',
                ],
                'point' => [
                    'subtotal' => '0.00',
                    'total' => '0.00',
                ],
                'shipping_fee' => '0.00'
            ],
            'delivery_info' => $request->delivery_info,
            'delivery_details' => $request->delivery_details,
            'is_voucher_applied' => false,
            'is_receipt_required' => $request->is_receipt_required ?? false
        ];

        /** @var Order $order */
        $order = Order::create($orderAttributes);

        /** @var Checkout $checkout */
        $checkout = $order->checkout()->create([
            'payment_method' => CheckoutType::ONLINE->value
        ]);

        // Update Order status
        $status = Str::slug(ShipmentDeliveryStatus::SUBMITTED->value);
        $order->updateStatus($status);

        return [
            'message' => 'Submitted Order successfully',
            'order_id' => $order->id
        ];
    }

    public function getAllOrders(Request $request)
    {
        $storeID = Alias::where('key', $request->store_id)
            ->latest()
            ->first()
            ?->value
            ?? $request->store_id;

        return Order::where('store_id', $storeID)
            ->when(
                $request->has('is_system'),
                function ($query) use ($request) {
                    $isSystem = filter_var($request->is_system, FILTER_VALIDATE_BOOLEAN);
                    $query->where('is_system', $isSystem);
                }
            )
            ->get();
    }

    public function getOrdersByBatchPreview(Request $request): array
    {
        $customer = $this->customer();

        $orders = Order::whereIn('_id', $request->order_ids)
            ->where('customer_id', $customer->id)
            ->get();

        $totalAmount = $orders->sum(function ($order) {
            return (float) $order->calculations['price']['total'];
        });

        return [
            'order_ids' => $orders->pluck('id')->all(),
            'total_amount' => $totalAmount
        ];
    }

    public function payOrdersByBatch(Request $request): array
    {
        $customer = $this->customer();

        $orders = Order::whereIn('_id', $request->order_ids)
            ->where('customer_id', $customer->id)
            ->get();

        $totalAmount = $orders->sum(function ($order) {
            return (float) $order->calculations['price']['total'];
        });

        // Clone orders
        $clonedOrderIDs = [];
        foreach ($orders as $order) {
            $duplicatedOrder = $order->replicate()
                ->fill([
                    'is_system' => false,
                    'delivery_info' => $request->delivery_info,
                    'delivery_details' => $request->delivery_details,
                    'is_paid' => false,
                    'system_order_id' => $order->_id,
                    'payment_method' => $request->payment_method
                ]);
            $duplicatedOrder->save();

            // Create 
            Checkout::create([
                'order_id' => $duplicatedOrder->id,
                'payment_method' => $request->payment_method
            ]);

            $clonedOrderIDs[] = $duplicatedOrder->id;
        }

        // Create BatchPayment
        $batchPayment = BatchPayment::create([
            'customer_id' => $customer->id,
            'order_ids' => $clonedOrderIDs,
            'total_amount' => number_format($totalAmount, 2, '.', ''),
            'currency' => 'HKD',
            'payment_method' => $request->payment_method
        ]);

        if ($request->payment_method === OrderPaymentMethod::ONLINE->value) {
            $stripeAmount = (int) $totalAmount * 100;

            $data = [
                'amount' => $stripeAmount,
                'currency' => 'HKD',
                'captureMethod' => 'automatic_async',
                'metadata' => [
                    'model_type' => 'batch_payment',
                    'model_id' => $batchPayment->id
                ]
            ];

            $url = env('RMHC_STRIPE_BASE_URL', 'https://payment.paraqon.starsnet.hk') . '/payment-intents';
            $res = Http::post($url, $data);

            $batchPayment->update([
                'payment_intent_id' => $res['id'],
                'client_secret' => $res['clientSecret'],
                'api_response' => null
            ]);
        }

        if ($request->payment_method === OrderPaymentMethod::OFFLINE->value) {
            $batchPayment->update([
                'payment_image' => $request->image,
                'payment_image_uploaded_at' => now()
            ]);
        }

        return [
            'message' => 'Created Transaction successfully',
            'batch_payment' => $batchPayment,
            'order_ids' => $orders->pluck('id')->all(),
        ];
    }
}
