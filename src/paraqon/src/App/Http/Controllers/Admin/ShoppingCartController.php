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
use App\Enums\Status;
use App\Models\Alias;
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
        $store = Store::find($request->route('store_id'))
            ?? Store::find(Alias::getValue($request->route('store_id')));

        return ShoppingCartItem::where('store_id', $store->_id)
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
        $store = Store::find($request->route('store_id'))
            ?? Store::find(Alias::getValue($request->route('store_id')));
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
        $store = Store::find($request->route('store_id'))
            ?? Store::find(Alias::getValue($request->route('store_id')));
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

    public function privateSaleGetAll(Request $request): array
    {
        $now = now();

        // Get authenticated User information
        $customerID = $request->customer_id;
        $customer = Customer::find($customerID);
        if (is_null($customer)) abort(404, 'Customer not found');

        /** @var ?Store $store */
        $this->store = Store::find($request->route('store_id'))
            ?? Store::find(Alias::getValue($request->route('store_id')));
        if (is_null($this->store)) abort(404, 'Store not found');

        // Extract attributes from $request
        $checkoutVariantIDs = $request->checkout_product_variant_ids;
        $voucherCode = $request->voucher_code;
        $deliveryInfo = $request->delivery_info;

        $courierID = $deliveryInfo['method'] === OrderDeliveryMethod::DELIVERY->value ?
            $deliveryInfo['courier_id'] :
            null;

        // Get ShoppingCartItem data
        $cartItems = $this->getCartItems($customer, $checkoutVariantIDs);
        $checkoutItems = $cartItems->filter(fn($item) => $item->is_checkout == true);

        $priceDetails = $this->calculatePriceDetails($checkoutItems);
        $validDiscounts = $this->getValidDiscounts(
            $this->store->id,
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

        // Service Fee
        $totalPrice = $calculation['price']['total'];
        $serviceFee = $request->input('fixed_fee', 0) + $totalPrice * $request->input('variable_fee', 0);
        $totalPricePlusServiceFee =  $totalPrice + $serviceFee;

        $calculation['service_fee'] = number_format($serviceFee, 2, '.', '');
        $calculation['total_price_plus_service_fee'] = number_format($totalPricePlusServiceFee, 2, '.', '');

        $isEnoughMembershipPoints = $this->checkMembershipPoints($customer, $calculation['point']['total'], $now);

        return [
            'cart_items' => $cartItems,
            'gift_items' => $giftItems,
            'discounts' => $this->formatDiscounts($discounts),
            'calculations' => $calculation,
            'is_voucher_applied' => !is_null($discounts['voucher']),
            'is_enough_membership_points' => $isEnoughMembershipPoints
        ];
    }

    public function privateSaleCheckOut(Request $request): array
    {
        $now = now();

        // Get authenticated User information
        $customerID = $request->customer_id;
        $customer = Customer::find($customerID);
        if (is_null($customer)) abort(404, 'Customer not found');

        /** @var ?Store $store */
        $this->store = Store::find($request->route('store_id'))
            ?? Store::find(Alias::getValue($request->route('store_id')));
        if (is_null($this->store)) abort(404, 'Store not found');

        // Extract attributes from $request
        $checkoutVariantIDs = $request->checkout_product_variant_ids;
        $voucherCode = $request->voucher_code;
        $deliveryInfo = $request->delivery_info;
        $deliveryDetails = $request->delivery_details;
        $paymentMethod = $request->payment_method;

        $courierID = $deliveryInfo['method'] === OrderDeliveryMethod::DELIVERY->value
            ? $deliveryInfo['courier_id']
            : null;

        // Get ShoppingCartItem(s)
        $cartItems = $this->getCartItems($customer, $checkoutVariantIDs);
        $checkoutItems = $cartItems->filter(fn($item) => $item->is_checkout == true);

        $priceDetails = $this->calculatePriceDetails($checkoutItems);
        $validDiscounts = $this->getValidDiscounts(
            $this->store->id,
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

        $isEnoughMembershipPoints = $this->checkMembershipPoints($customer, $calculation['point']['total'], $now);
        if (!$isEnoughMembershipPoints) abort(403, 'Customer does not have enough membership points for this transaction');

        // Create Order
        $totalPrice = $calculation['price']['total'];
        if ($totalPrice <= 0) $paymentMethod = CheckoutType::OFFLINE->value;

        // Service Fee
        $fixedFee = (float) $request->input('fixed_fee', 0);
        $discount = (float) $request->input('discount', 0);
        $amountReceived = $totalPrice + $fixedFee - $discount;

        // Create Order
        $orderAttributes = [
            'customer_id' => $customer->id,
            'store_id' => $this->store->id,
            'is_paid' => $request->input('is_paid', false),
            'payment_method' => $paymentMethod,
            'discounts' => $this->formatDiscounts($discounts),
            'calculations' => $calculation,
            'delivery_info' => $deliveryInfo,
            'delivery_details' => $deliveryDetails,
            'is_voucher_applied' => !is_null($discounts['voucher']),
            'fixed_fee' => number_format($fixedFee, 2, '.', ''),
            'discount' => number_format($discount, 2, '.', ''),
            'amount_received' => number_format($amountReceived, 2, '.', '')
        ];
        /** @var Order $order */
        $order = Order::create($orderAttributes);

        foreach ($checkoutItems->values()->all() as $cartItem) {
            $cartItem = $cartItem->toArray();
            $order->orderCartItems()->create($cartItem);
        }
        foreach ($giftItems as $cartItem) {
            $order->orderGiftItems()->create($cartItem);
        }

        // Update Order
        $status = Str::slug(ShipmentDeliveryStatus::SUBMITTED->value);
        $order->updateStatus($status);

        // Create Checkout
        $checkout = Checkout::create([
            'order_id' => $order->id,
            'payment_method' => $paymentMethod,
        ]);

        $returnUrl = null;
        if ($order->getTotalPrice() > 0) {
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
                    break;
                default:
                    abort(404, 'Invalid payment_method');
                    break;
            }
        } else {
            // Distribute MembershipPoint
            $this->processMembershipPoints($customer, $order);
        }

        // Update MembershipPoint, for Offline Payment via MINI Store
        $totalPoints = $calculation['point']['total'];
        if ($paymentMethod === CheckoutType::OFFLINE->value && $totalPoints > 0) {
            $history = $customer->deductMembershipPoints($totalPoints);

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
                ->where('store_id', $this->store->id)
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
            if ($voucher) $voucher->usedByOrder($order);
        }

        return [
            'message' => 'Submitted Order successfully',
            'return_url' => $returnUrl ?? null,
            'order_id' => $order->_id
        ];
    }
}
