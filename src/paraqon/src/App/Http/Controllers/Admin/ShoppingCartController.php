<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Traits
use App\Http\Controllers\Traits\ShoppingCartTrait;
use App\Http\Controllers\Traits\DistributePointTrait;

// Enums
use App\Enums\CheckoutType;
use App\Enums\OrderDeliveryMethod;
use App\Enums\ShipmentDeliveryStatus;

// Models
use App\Models\Checkout;
use App\Models\Courier;
use App\Models\Customer;
use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\ShoppingCartItem;
use App\Models\Store;
use App\Models\Warehouse;

class ShoppingCartController extends Controller
{
    use ShoppingCartTrait,
        DistributePointTrait;

    public function getShoppingCartItems(Request $request): Collection
    {
        return ShoppingCartItem::where('store_id', $request->store_id)
            ->where('customer_id', $request->customer_id)
            ->get()
            ->append([
                // Product information related
                'product_title',
                'product_variant_title',
                'image',
                // Calculation-related
                'local_discount_type',
                'original_price_per_unit',
                'discounted_price_per_unit',
                'original_subtotal_price',
                'subtotal_price',
                'original_point_per_unit',
                'discounted_point_per_unit',
                'original_subtotal_point',
                'subtotal_point',
            ])
            ->each(function ($item) {
                $item->is_checkout = true;
                $item->is_refundable = false;
                $item->global_discount = null;
            });
    }

    public function getAll(Request $request)
    {
        $now = now();

        /** @var ?Customer $customer */
        $customer = Customer::find($request->customer_id);
        if (is_null($customer)) abort(404, 'Customer not found');

        /** @var ?Store $store */
        $store = Store::find($request->route('store_id'));
        if (is_null($store)) abort(404, 'Store not found');

        // Extract attributes from $request
        $checkoutVariantIDs = $request->checkout_product_variant_ids;
        $voucherCode = $request->voucher_code;
        $deliveryInfo = $request->delivery_info;
        $deliveryDetails = $request->delivery_details;

        $courierID = $deliveryInfo['method'] === OrderDeliveryMethod::DELIVERY->value
            ? $deliveryInfo['courier_id']
            : null;

        // Get Checkout information
        $cartItems = $this->getCartItems($customer, $checkoutVariantIDs);
        $checkoutItems = $cartItems->filter(fn($item) => $item->is_checkout == true);

        $priceDetails = $this->calculatePriceDetails($checkoutItems);
        $validDiscounts = $this->getValidDiscounts(
            $store->id,
            $customer,
            $priceDetails['totalPrice'],
            $priceDetails['productQty'],
            $now
        );

        $discounts = $this->processDiscounts(
            $validDiscounts,
            $priceDetails['totalPrice'],
            $checkoutItems,
            $voucherCode,
            $now
        );
        $giftItems = $this->processGiftItems($discounts, $checkoutItems);

        $calculation = $this->calculateTotals(
            $priceDetails['subtotalPrice'],
            $priceDetails['localPriceDiscount'],
            $discounts['bestPrice']->discounted_value ?? 0,
            $checkoutItems,
            $courierID,
            $discounts['freeShipping'],
            $discounts['voucher']
        );

        $requiredPoints = $calculation['point']['total'];
        $isEnoughMembershipPoints = $this->checkMembershipPoints($customer, $requiredPoints, $now);

        return [
            'cart_items' => $cartItems,
            'gift_items' => $giftItems,
            'discounts' => $this->formatDiscounts($discounts),
            'calculations' => $calculation,
            'delivery_info' => $deliveryInfo,
            'delivery_details' => $deliveryDetails,
            'is_voucher_applied' => !is_null($discounts['voucher']),
            'is_enough_membership_points' => $isEnoughMembershipPoints
        ];
    }

    public function checkOut(Request $request)
    {
        $now = now();

        /** @var ?Customer $customer */
        $customer = Customer::find($request->customer_id);
        if (is_null($customer)) abort(404, 'Customer not found');

        /** @var ?Store $store */
        $store = Store::find($request->route('store_id'));
        if (is_null($store)) abort(404, 'Store not found');

        // Extract attributes from $request
        $checkoutVariantIDs = $request->checkout_product_variant_ids;
        $voucherCode = $request->voucher_code;
        $deliveryInfo = $request->delivery_info;
        $deliveryDetails = $request->delivery_details;
        $paymentMethod = $request->payment_method;

        $courierID = $deliveryInfo['method'] === OrderDeliveryMethod::DELIVERY->value
            ? $deliveryInfo['courier_id']
            : null;

        // Get Checkout information
        $cartItems = $this->getCartItems($customer, $checkoutVariantIDs);
        $checkoutItems = $cartItems->filter(fn($item) => $item->is_checkout == true);

        $priceDetails = $this->calculatePriceDetails($checkoutItems);
        $validDiscounts = $this->getValidDiscounts(
            $store->id,
            $customer,
            $priceDetails['totalPrice'],
            $priceDetails['productQty'],
            $now
        );

        $discounts = $this->processDiscounts(
            $validDiscounts,
            $priceDetails['totalPrice'],
            $checkoutItems,
            $voucherCode,
            $now
        );
        $giftItems = $this->processGiftItems($discounts, $checkoutItems);

        // Get calculation
        $calculation = $this->calculateTotals(
            $priceDetails['subtotalPrice'],
            $priceDetails['localPriceDiscount'],
            $discounts['bestPrice']->discounted_value ?? 0,
            $checkoutItems,
            $courierID,
            $discounts['freeShipping'],
            $discounts['voucher']
        );

        // Validate Customer membership points
        $requiredPoints = $calculation['point']['total'];
        $isEnoughMembershipPoints = $this->checkMembershipPoints($customer, $requiredPoints, $now);
        if (!$isEnoughMembershipPoints) abort(403, 'Customer does not have enough membership points for this transaction');

        // Create Order
        $totalPrice = $calculation['price']['total'];
        if ($totalPrice <= 0) $paymentMethod = CheckoutType::OFFLINE->value;

        // Update $deliveryInfo
        if (!empty($deliveryInfo) && isset($deliveryInfo['method'])) {
            switch ($deliveryInfo['method']) {
                case OrderDeliveryMethod::DELIVERY->value:
                    /** @var Courier $courier */
                    $courier = Courier::find($deliveryInfo['courier_id']);
                    if (!$courier) break;

                    $courierInfo = [
                        'title' => $courier->title,
                        'image' => $courier->images[0] ?? null,
                    ];
                    $deliveryInfo['courier'] = $courierInfo;
                    break;
                case OrderDeliveryMethod::SELF_PICKUP->value:
                    /** @var Warehouse $warehouse */
                    $warehouse = Warehouse::find($deliveryInfo['warehouse_id']);
                    if (!$warehouse) break;

                    $warehouseInfo = [
                        'title' => $warehouse->title,
                        'image' => $warehouse->images[0] ?? null,
                        'location' => $warehouse->location
                    ];
                    $deliveryInfo['warehouse'] = $warehouseInfo;
                    break;
                default:
                    break;
            }
        }

        // Create Order
        $orderAttributes = [
            'cashier_id' => $request->cashier_id,
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'is_paid' => $request->input('is_paid', false),
            'payment_method' => $paymentMethod,
            'cart_items' => $checkoutItems,
            'gift_items' => $giftItems,
            'discounts' => $this->formatDiscounts($discounts),
            'calculations' => $calculation,
            'delivery_info' => $deliveryInfo,
            'delivery_details' => $deliveryDetails,
            'is_voucher_applied' => !is_null($discounts['voucher']),
        ];

        /** @var Order $order */
        $order = Order::create($orderAttributes);
        $order->updateStatus(Str::slug(ShipmentDeliveryStatus::SUBMITTED->value));

        // Create Checkout
        $checkout = Checkout::create([
            'order_id' => $order->id,
            'payment_method' => $paymentMethod,
        ]);

        if ($totalPrice > 0) {
            switch ($paymentMethod) {
                case CheckoutType::ONLINE->value:
                    $data = [
                        "amount" => (int) $totalPrice * 100,
                        "currency" => 'HKD',
                        "captureMethod" => "automatic_async",
                        "metadata" => [
                            "model_type" => "checkout",
                            "model_id" => $checkout->_id
                        ]
                    ];

                    $url = env('PARAQON_STRIPE_BASE_URL', 'https://payment.paraqon.starsnet.hk') . '/payment-intents';
                    $res = Http::post($url, $data);

                    $checkout->update([
                        'amount' => $totalPrice,
                        'currency' => $request->currency ?? 'HKD',
                        'online' => [
                            'payment_intent_id' => $res['id'] ?? null,
                            'client_secret' => $res['clientSecret'] ?? null,
                            'api_response' => null
                        ],
                    ]);

                    return [
                        'message' => 'Submitted Order successfully',
                        'checkout' => $checkout,
                        'order_id' => $order->_id
                    ];
                case CheckoutType::OFFLINE->value:
                    $checkout->update([
                        'offline' => [
                            'image' => $request->image,
                            'uploaded_at' => $now,
                            'api_response' => null
                        ]
                    ]);

                    return [
                        'message' => 'Submitted Order successfully',
                        'checkout' => $checkout,
                        'order_id' => $order->_id
                    ];
                default:
                    return response()->json(['message' => 'Invalid payment_method'], 404);
            }
        } else {
            $this->processMembershipPoints($customer, $order);
        }

        // Update MembershipPoint, for Offline Payment via MINI Store
        $requiredPoints = $calculation['point']['total'] ?? 0;
        if ($paymentMethod === CheckoutType::OFFLINE->value && $requiredPoints > 0) {
            $history = $customer->deductMembershipPoints($requiredPoints);

            $description = 'Redemption Record for Order ID: ' . $order->_id;
            $historyAttributes = [
                'description' => [
                    'en' => $description,
                    'zh' => $description,
                    'cn' => $description,
                ],
                'remarks' => $description
            ];
            $history->update($historyAttributes);
        }

        // Delete ShoppingCartItem(s)
        if ($paymentMethod === CheckoutType::OFFLINE->value) {
            ShoppingCartItem::where('customer_id', $customer->id)
                ->where('store_id', $store->id)
                ->whereIn('product_variant_id', $checkoutVariantIDs)
                ->delete();
        }

        // Use voucherCode
        if (!is_null($voucherCode)) {
            /** @var ?DiscountCode $voucher */
            $voucher = DiscountCode::where('full_code', $voucherCode)
                ->where('is_used', false)
                ->where('is_disabled', false)
                ->first();

            if (!is_null($voucher)) $voucher->usedByOrder($order);
        }

        return [
            'message' => 'Submitted Order successfully',
            'checkout' => $checkout,
            'order_id' => $order->_id
        ];
    }
}
