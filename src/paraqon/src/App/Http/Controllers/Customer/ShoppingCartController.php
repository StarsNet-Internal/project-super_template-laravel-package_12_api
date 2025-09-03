<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Collection;

// Traits
use App\Http\Controllers\Traits\DistributePointTrait;

// Services
use App\Http\Starsnet\PinkiePay;

// Enums
use App\Enums\CheckoutType;
use App\Enums\OrderDeliveryMethod;
use App\Enums\ShipmentDeliveryStatus;
use App\Enums\WarehouseInventoryHistoryType;
use App\Enums\DiscountTemplateDiscountType;
use App\Enums\DiscountTemplateType;

// Models
use App\Models\Alias;
use App\Models\Checkout;
use App\Models\Courier;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\DiscountCode;
use App\Models\DiscountTemplate;
use App\Models\ProductVariant;
use App\Models\ShoppingCartItem;
use App\Models\Store;
use App\Models\Warehouse;
use Starsnet\Project\Paraqon\App\Models\AuctionLot;

class ShoppingCartController extends Controller
{
    use DistributePointTrait;

    private function getStore(string $storeID): ?Store
    {
        return Store::find($storeID) ?? Store::find(Alias::getValue($storeID));
    }

    public function getAllAuctionCartItems(Request $request): array
    {
        // Extract attributes from $request
        $store = $this->getStore($request->route('store_id'));
        $checkoutVariantIDs = $request->checkout_product_variant_ids;

        // Get authenticated User information
        $customer = $this->customer();

        // Get ShoppingCartItem(s)
        $cartItems = ShoppingCartItem::where('customer_id', $customer->id)
            ->where('store_id', $store->id)
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
            ->each(function ($item) use ($checkoutVariantIDs) {
                $item->is_checkout = in_array($item->product_variant_id, $checkoutVariantIDs);
                $item->is_refundable = false;
                $item->global_discount = null;
            });

        // Extract attributes from $request
        $currency = $request->input('currency', 'HKD');
        $deliveryInfo = $request->delivery_info;

        $courierID = $deliveryInfo['method'] === OrderDeliveryMethod::DELIVERY->value ?
            $deliveryInfo['courier_id'] :
            null;
        $countryCode = strtoupper($deliveryInfo['country_code'] ?? 'HK');

        // getShoppingCartDetails calculations
        // get subtotal Price
        $subtotalPrice = 0;
        $shippingFee = 0;

        // $SERVICE_CHARGE_MULTIPLIER = 0.1;
        $totalServiceCharge = 0;

        foreach ($cartItems as $item) {
            // Find AuctionLot
            $lot = AuctionLot::where('store_id', $store->id)
                ->where('product_id', $item->product_id)
                ->first();

            // Calculations
            // $winningBid = (float) $item->winning_bid ?? 0;
            $winningBid = (float) optional($lot)->current_bid ?? 0;
            $item->winning_bid = $winningBid;

            // Add new keys
            $item->sold_price = optional($lot)->sold_price ?? optional($lot)->current_bid;
            $item->commission = optional($lot)->commission ?? 0;

            if ($item->is_checkout) {
                $subtotalPrice += $item->sold_price;
            }

            // Service Charge
            // $totalServiceCharge += $winningBid *
            //     $SERVICE_CHARGE_MULTIPLIER;

            // Shipping Fee
            $item->shipping_fee = 0;
            $shippingCosts = $lot->shipping_costs;
            $matchingShippingCostElement = collect($shippingCosts)->firstWhere('area', $countryCode);

            if (!is_null($matchingShippingCostElement)) {
                $item->shipping_fee =
                    (float) $matchingShippingCostElement['cost'];

                if ($item->is_checkout) {
                    $shippingFee +=
                        (float) $matchingShippingCostElement['cost'];
                }
            }
        }
        $totalPrice = $subtotalPrice + $totalServiceCharge;

        // get shippingFee
        $courier = Courier::find($courierID);
        $shippingFee += !is_null($courier)
            ? $courier->getShippingFeeByTotalFee($totalPrice)
            : 0;
        $totalPrice += $shippingFee;

        // Find system order
        $systemOrder = Order::where('store_id', $store->id)
            ->where('customer_id', $customer->_id)
            ->where('is_system', true)
            ->first();
        $systemOrderDeposit = 0;
        if (!is_null($systemOrder)) {
            $systemOrderDeposit = $systemOrder->calculations['deposit'];
        }

        // Update total price
        $totalPrice -= $systemOrderDeposit;

        // credit_card_charge_percentage
        $creditCardChargePercentage = (float) $request->input('credit_card_charge_percentage', 0);
        $creditCardChargeFee = floor(($totalPrice * $creditCardChargePercentage) / 100);
        $totalPrice = $totalPrice + $creditCardChargeFee;

        // form calculation data object
        $rawCalculation = [
            'currency' => $currency,
            'price' => [
                'subtotal' => number_format($subtotalPrice, 2, '.', ''),
                'total' => number_format(ceil($totalPrice), 2, '.', ''), // Deduct price_discount.local and .global
            ],
            'price_discount' => [
                'local' => '0.00',
                'global' => '0.00',
            ],
            'point' => [
                'subtotal' => '0.00',
                'total' => '0.00',
            ],
            'service_charge' => number_format($totalServiceCharge, 2, '.', ''),
            'credit_card_charge_fee' => number_format($creditCardChargeFee, 2, '.', ''),
            'deposit' => number_format($systemOrderDeposit, 2, '.', ''),
            'storage_fee' => '0.00',
            'shipping_fee' => number_format($shippingFee, 2, '.', '')
        ];

        return [
            'cart_items' => $cartItems,
            'gift_items' => [],
            'discounts' => [],
            'calculations' => $rawCalculation,
            'is_voucher_applied' => false,
            'is_enough_membership_points' => true
        ];
    }

    public function getAllMainStoreCartItems(Request $request): array
    {
        $storeID = $request->route('store_id');
        $store = $this->getStore($storeID);

        // Extract attributes from $request
        $checkoutVariantIDs = $request->checkout_product_variant_ids;
        $deliveryInfo = $request->delivery_info;

        // Get authenticated User information
        $customer = $this->customer();

        // Winning auction lots by Customer
        $selectedItems = ShoppingCartItem::where('customer_id', $customer->id)
            ->where('store_id', $store->id)
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
            ]);

        foreach ($selectedItems as $item) {
            $item->update([
                'winning_bid' => 0,
                'storage_fee' => 0
            ]);
        }

        // Get ShoppingCartItem(s)
        $cartItems = ShoppingCartItem::where('customer_id', $customer->id)
            ->where('store_id', $store->id)
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
            ->each(function ($item) use ($checkoutVariantIDs) {
                $item->is_checkout = in_array($item->product_variant_id, $checkoutVariantIDs);
                $item->is_refundable = false;
                $item->global_discount = null;
            });

        // Extract attributes from $request
        $deliveryInfo = $request->delivery_info;

        $courierID = $deliveryInfo['method'] === OrderDeliveryMethod::DELIVERY->value ?
            $deliveryInfo['courier_id'] :
            null;

        // getShoppingCartDetails calculations
        // get subtotal Price
        $subtotalPrice = 0;
        $storageFee = 0;

        foreach ($cartItems as $item) {
            // Calculations
            $winningBid = $item->winning_bid ?? 0;
            $subtotalPrice += $winningBid;

            $storageFee += $item->storage_fee ?? 0;
        }
        $totalPrice = $subtotalPrice + $storageFee;

        // get shippingFee
        $courier = Courier::find($courierID);
        $shippingFee = !is_null($courier) ?
            $courier->getShippingFeeByTotalFee($totalPrice) :
            0;
        $totalPrice += $shippingFee;

        // form calculation data object
        $rawCalculation = [
            'currency' => 'HKD',
            'price' => [
                'subtotal' => number_format($subtotalPrice, 2, '.', ''),
                'total' => number_format(floor($totalPrice), 2, '.', ''), // Deduct price_discount.local and .global
            ],
            'price_discount' => [
                'local' => '0.00',
                'global' => '0.00',
            ],
            'point' => [
                'subtotal' => '0.00',
                'total' => '0.00',
            ],
            'service_charge' => '0.00',
            'storage_fee' => number_format($storageFee, 2, '.', ''),
            'shipping_fee' => number_format($shippingFee, 2, '.', '')
        ];

        return [
            'cart_items' => $cartItems,
            'gift_items' => [],
            'discounts' => [],
            'calculations' => $rawCalculation,
            'is_voucher_applied' => false,
            'is_enough_membership_points' => true
        ];
    }

    public function checkOutAuctionStore(Request $request)
    {
        $now = now();

        // Extract attributes from $request
        $store = $this->getStore($request->route('store_id'));
        $isPaid = $request->boolean('is_paid', false);

        // Get authenticated User information
        $customer = $this->customer();
        $checkoutVariantIDs = $request->checkout_product_variant_ids;

        // Get ShoppingCartItem(s)
        $cartItems = ShoppingCartItem::where('customer_id', $customer->id)
            ->where('store_id', $store->id)
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
            ->each(function ($item) use ($checkoutVariantIDs) {
                $item->is_checkout = in_array($item->product_variant_id, $checkoutVariantIDs);
                $item->is_refundable = false;
                $item->global_discount = null;
            });

        // Extract attributes from $request
        $currency = $request->input('currency', 'HKD');
        $conversionRate = $request->input('conversion_rate', '1.00');

        $deliveryInfo = $request->delivery_info;
        $deliveryDetails = $request->delivery_details;
        $paymentMethod = $request->payment_method;

        $courierID = $deliveryInfo['method'] === OrderDeliveryMethod::DELIVERY ?
            $deliveryInfo['courier_id'] :
            null;

        $countryCode = $deliveryInfo['country_code'] ?? 'HK';
        $countryCode = strtoupper($countryCode);

        // getShoppingCartDetails calculations
        // get subtotal Price
        $subtotalPrice = 0;
        $shippingFee = 0;

        // $SERVICE_CHARGE_MULTIPLIER = 0.1;
        $totalServiceCharge = 0;

        foreach ($cartItems as $item) {
            // Find AuctionLot
            $lot = AuctionLot::where('store_id', $store->id)
                ->where('product_id', $item->product_id)
                ->first();

            // Add keys
            $item->lot_number = $lot->lot_number;

            // Calculations
            // $winningBid = (float) $item->winning_bid ?? 0;
            $winningBid = (float) optional($lot)->current_bid ?? 0;
            $item->winning_bid = $winningBid;

            // Add new keys
            $item->sold_price = optional($lot)->sold_price ?? optional($lot)->current_bid;
            $item->commission = optional($lot)->commission ?? 0;

            if ($item->is_checkout) {
                $subtotalPrice += $item->sold_price;
            }

            // Shipping Fee
            $item->shipping_fee = 0;
            $shippingCosts = $lot->shipping_costs;
            $matchingShippingCostElement = collect($shippingCosts)
                ->firstWhere(
                    'area',
                    $countryCode
                );

            if (!is_null($matchingShippingCostElement)) {
                $item->shipping_fee =
                    (float) $matchingShippingCostElement['cost'];

                if ($item->is_checkout) {
                    $shippingFee +=
                        (float) $matchingShippingCostElement['cost'];
                }
            }
        }
        $totalPrice = $subtotalPrice + $totalServiceCharge;

        // get shippingFee
        $courier = Courier::find($courierID);
        $shippingFee =
            !is_null($courier) ?
            $courier->getShippingFeeByTotalFee($totalPrice) :
            0;
        $totalPrice += $shippingFee;

        // Find system order
        $systemOrder = Order::where('store_id', $store->id)
            ->where('customer_id', $customer->_id)
            ->where('is_system', true)
            ->first();
        $systemOrderDeposit = $systemOrder->calculations['deposit'];

        // Update total price
        $totalPrice -= $systemOrderDeposit;

        // credit_card_charge_percentage
        $creditCardChargePercentage = (float) $request->input('credit_card_charge_percentage', 0);
        $creditCardChargeFee = floor(($totalPrice * $creditCardChargePercentage) / 100);
        $totalPrice = $totalPrice + $creditCardChargeFee;

        // form calculation data object
        $rawCalculation = [
            'currency' => 'HKD',
            'price' => [
                'subtotal' => number_format($subtotalPrice, 2, '.', ''),
                'total' => number_format(ceil($totalPrice), 2, '.', ''), // Deduct price_discount.local and .global
            ],
            'price_discount' => [
                'local' => '0.00',
                'global' => '0.00',
            ],
            'point' => [
                'subtotal' => '0.00',
                'total' => '0.00',
            ],
            'service_charge' => number_format($totalServiceCharge, 2, '.', ''),
            'credit_card_charge_fee' => number_format($creditCardChargeFee, 2, '.', ''),
            'deposit' => number_format($systemOrderDeposit, 2, '.', ''),
            'storage_fee' => '0.00',
            'shipping_fee' => number_format($shippingFee, 2, '.', '')
        ];

        // Return data
        $checkoutDetails = [
            'cart_items' => $cartItems,
            'gift_items' => [],
            'discounts' => [],
            'calculations' => $rawCalculation,
            'is_voucher_applied' => false,
            'is_enough_membership_points' => true
        ];

        // Validate, and update attributes
        $totalPrice = $checkoutDetails['calculations']['price']['total'];
        if ($totalPrice <= 0) {
            $paymentMethod = CheckoutType::OFFLINE->value;
            $isPaid = true;
        }

        // Create Order
        $orderAttributes = [
            'is_paid' => $isPaid,
            'payment_method' => $paymentMethod,
            'discounts' => $checkoutDetails['discounts'],
            'calculations' => $checkoutDetails['calculations'],
            'delivery_info' => $this->getDeliveryInfo($deliveryInfo),
            'delivery_details' => $deliveryDetails,
            'is_voucher_applied' => $checkoutDetails['is_voucher_applied'],
            'is_system' => false,
            'payment_information' => [
                'currency' => $currency,
                'conversion_rate' => $conversionRate
            ]
        ];
        $order = $customer->createOrder($orderAttributes, $store);

        // Create OrderCartItem(s)
        $checkoutItems = collect($checkoutDetails['cart_items'])
            ->filter(function ($item) {
                return $item->is_checkout;
            })->values();

        foreach ($checkoutItems as $item) {
            $attributes = $item->toArray();
            unset(
                $attributes['_id'],
                $attributes['is_checkout']
            );

            // Update WarehouseInventory(s)
            $variantID = $attributes['product_variant_id'];
            $qty = $attributes['qty'];
            /** @var ProductVariant $variant */
            $variant = ProductVariant::find($variantID);
            $this->deductWarehouseInventoriesByStore(
                $store,
                $variant,
                $qty,
                WarehouseInventoryHistoryType::SALES->value,
                $customer->getUser()
            );

            $order->createCartItem($attributes);
        }

        // Update Order
        $status = Str::slug(ShipmentDeliveryStatus::SUBMITTED->value);
        $order->updateStatus($status);

        // Create Checkout
        $checkout = $this->createBasicCheckout($order, $paymentMethod);

        if ($order->getTotalPrice() > 0) {
            switch ($paymentMethod) {
                case CheckoutType::ONLINE->value:
                    $stripeAmount = (int) $totalPrice * 100;

                    $data = [
                        'amount' => $stripeAmount,
                        'currency' => 'HKD',
                        'captureMethod' => 'automatic_async',
                        'metadata' => [
                            'model_type' => 'checkout',
                            'model_id' => $checkout->_id
                        ]
                    ];

                    $url = env('PARAQON_STRIPE_BASE_URL', 'https://payment.paraqon.starsnet.hk') . '/payment-intents';
                    $res = Http::post($url, $data);
                    $checkout->update([
                        'amount' => $totalPrice,
                        'currency' => $currency,
                        'online' => [
                            'payment_intent_id' => $res['id'],
                            'client_secret' => $res['clientSecret'],
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
                    return ['message' => 'Invalid payment_method'];
            }
        }

        return [
            'message' => 'Submitted Order successfully',
            'checkout' => $checkout,
            'order_id' => $order->_id
        ];
    }

    public function checkOutMainStore(Request $request)
    {
        $now = now();

        $store = $this->getStore($request->route('store_id'));

        // Get authenticated User information
        $customer = $this->customer();
        $checkoutVariantIDs = $request->checkout_product_variant_ids;

        // Get ShoppingCartItem(s)
        $cartItems = ShoppingCartItem::where('customer_id', $customer->id)
            ->where('store_id', $store->id)
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
            ->each(function ($item) use ($checkoutVariantIDs) {
                $item->is_checkout = in_array($item->product_variant_id, $checkoutVariantIDs);
                $item->is_refundable = false;
                $item->global_discount = null;
            });

        // Extract attributes from $request
        $deliveryInfo = $request->delivery_info;
        $deliveryDetails = $request->delivery_details;
        $paymentMethod = $request->payment_method;

        $courierID = $deliveryInfo['method'] === OrderDeliveryMethod::DELIVERY->value ?
            $deliveryInfo['courier_id'] :
            null;

        // getShoppingCartDetails calculations
        $subtotalPrice = $cartItems->sum('subtotal_price');
        $localPriceDiscount = 0;
        $totalPrice = $subtotalPrice - $localPriceDiscount;

        $shippingFee = 0;
        if (!is_null($courierID)) {
            $courier = Courier::find($courierID);
            $shippingFee = !is_null($courier) ?
                $courier->getShippingFeeByTotalFee($totalPrice) :
                0;
        }
        $totalPrice += $shippingFee;

        $rawCalculation = [
            'currency' => 'HKD',
            'price' => [
                'subtotal' => $subtotalPrice,
                'total' => $totalPrice, // Deduct price_discount.local and .global
            ],
            'price_discount' => [
                'local' => '0.00',
                'global' => '0.00',
            ],
            'point' => [
                'subtotal' => '0.00',
                'total' => '0.00',
            ],
            'shipping_fee' => $shippingFee
        ];

        $checkoutDetails = [
            'cart_items' => $cartItems,
            'gift_items' => [],
            'discounts' => [],
            'calculations' => $rawCalculation,
            'is_voucher_applied' => false,
            'is_enough_membership_points' => true,
            'paid_order_id' => null,
            'is_storage' => false
        ];

        // Validate, and update attributes
        $totalPrice = $checkoutDetails['calculations']['price']['total'];
        if ($totalPrice <= 0) $paymentMethod = CheckoutType::OFFLINE->value;

        // Create Order
        $order = Order::create([
            'store_id' => $store->_id,
            'customer_id' => $customer->id,
            'is_paid' => $request->input('is_paid', false),
            'payment_method' => $paymentMethod,
            'discounts' => $checkoutDetails['discounts'],
            'calculations' => $checkoutDetails['calculations'],
            'delivery_info' => $this->getDeliveryInfo($deliveryInfo),
            'delivery_details' => $deliveryDetails,
            'is_voucher_applied' => $checkoutDetails['is_voucher_applied'],
        ]);

        // Create OrderCartItem(s)
        $checkoutItems = collect($checkoutDetails['cart_items'])
            ->filter(fn($item) => $item->is_checkout)
            ->values();

        foreach ($checkoutItems as $item) {
            $attributes = $item->toArray();
            unset($attributes['_id'], $attributes['is_checkout']);

            // Update WarehouseInventory(s)
            $variantID = $attributes['product_variant_id'];
            $qty = $attributes['qty'];
            /** @var ProductVariant $variant */
            $variant = ProductVariant::find($variantID);
            $this->deductWarehouseInventoriesByStore(
                $store,
                $variant,
                $qty,
                WarehouseInventoryHistoryType::SALES->value,
                $customer->getUser()
            );

            $order->createCartItem($attributes);
        }

        // Update Order
        $status = Str::slug(ShipmentDeliveryStatus::SUBMITTED->value);
        $order->updateStatus($status);

        // Create Checkout
        $checkout = $this->createBasicCheckout($order, $paymentMethod);

        if ($order->getTotalPrice() > 0) {
            switch ($paymentMethod) {
                case CheckoutType::ONLINE->value:
                    $stripeAmount = (int) $totalPrice * 100;

                    $data = [
                        'amount' => $stripeAmount,
                        'currency' => 'HKD',
                        'captureMethod' => 'manual',
                        'metadata' => [
                            'model_type' => 'checkout',
                            'model_id' => $checkout->_id,
                            'custom_event_type' => 'one_day_delay'
                        ]
                    ];

                    $url = env('PARAQON_STRIPE_BASE_URL', 'https://payment.paraqon.starsnet.hk') . '/payment-intents';
                    $res = Http::post($url, $data);

                    $checkout->update([
                        'amount' => number_format($totalPrice, 2, '.', ''),
                        'currency' => 'HKD',
                        'online' => [
                            'payment_intent_id' => $res['id'],
                            'client_secret' => $res['clientSecret'],
                            'api_response' => null
                        ],
                    ]);
                    break;
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
        }

        if ($paymentMethod === CheckoutType::OFFLINE->value) {
            // Delete ShoppingCartItem(s)
            $variants = ProductVariant::whereIn('_id', $request->checkout_product_variant_ids)->get();
            $customer->clearCartByStore($store, $variants);

            // Update product
            foreach ($variants as $variant) {
                $product = $variant->product;
                $product->update(['listing_status' => 'ALREADY_CHECKOUT']);
            }
        }

        return [
            'message' => 'Submitted Order successfully',
            'order_id' => $order->_id,
            'order' => $order,
            'checkout' => $checkout
        ];
    }

    public function checkOut(Request $request)
    {
        // Extract attributes from $request
        $storeID = $request->route('store_id');
        $store = $this->getStore($storeID);
        $isPaid = $request->input('is_paid', false);

        // Get authenticated User information
        $customer = $this->customer();
        $checkoutVariantIDs = $request->checkout_product_variant_ids;

        // Get ShoppingCartItem(s)
        $cartItems = ShoppingCartItem::where('customer_id', $customer->id)
            ->where('store_id', $store->id)
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
            ->each(function ($item) use ($checkoutVariantIDs) {
                $item->is_checkout = in_array($item->product_variant_id, $checkoutVariantIDs);
                $item->is_refundable = false;
                $item->global_discount = null;
            });

        // Extract attributes from $request
        $currency = $request->input('currency', 'HKD');
        $conversionRate = $request->input('conversion_rate', '1.00');

        $voucherCode = $request->voucher_code;
        $deliveryInfo = $request->delivery_info;
        $deliveryDetails = $request->delivery_details;
        $paymentMethod = $request->payment_method;

        $courierID = $deliveryInfo['method'] === OrderDeliveryMethod::DELIVERY->value ?
            $deliveryInfo['courier_id'] :
            null;
        $warehouseID = $deliveryInfo['method'] === OrderDeliveryMethod::SELF_PICKUP->value ?
            $deliveryInfo['warehouse_id'] :
            null;

        // Filter checkoutItems
        foreach ($cartItems as $item) {
            $product = Product::find($item->product_id);
            if (is_null($product)) $item->is_refundable = false;
            $item->is_refundable = $product->is_refundable;
        }

        // Update is_checkout on all items
        foreach ($cartItems as $item) {
            $item->is_checkout = in_array($item->product_variant_id, $checkoutVariantIDs);
        }

        // Filter is_checkout items
        $checkoutCartItems = $cartItems->filter(function ($item) {
            return $item->is_checkout;
        });

        // Get Applied DiscountTemplate(s) (Global Discount)
        $currentTotalPrice = $cartItems->sum('subtotal_price');
        $purchasedProductQty = $cartItems->sum('qty');

        $priceDiscounts = $this->getValidPriceOrPercentageDiscount($store, $customer, $currentTotalPrice, $purchasedProductQty);
        $giftDiscounts = $this->getAllValidBuyXGetYFreeDiscounts($store, $customer, $currentTotalPrice, $purchasedProductQty, $checkoutCartItems);
        $shippingDiscounts = $this->getFreeShippingDiscounts($store, $customer, $currentTotalPrice, $purchasedProductQty);
        $voucherDiscount = $this->getVoucherDiscount($voucherCode, $store, $customer, $currentTotalPrice, $purchasedProductQty);
        $allDiscounts = $priceDiscounts->merge($giftDiscounts)
            ->merge($voucherDiscount)
            ->merge($shippingDiscounts)
            ->filter()
            ->values();

        $mappedDiscounts = $allDiscounts->map(function ($item) {
            return [
                'code' => $item['prefix'],
                'title' => $item['title'],
                'description' => $item['description'],
            ];
        });

        // Get gift_items
        $giftItems = $this->getGiftItems($checkoutCartItems, $allDiscounts);

        // Get calculations
        // Get local discount
        $subtotalPrice = $cartItems->sum('original_subtotal_price');
        $localPriceDiscount =
            $cartItems->sum(function ($item) {
                return $item['original_subtotal_price'] - $item['subtotal_price'];
            });
        $totalPrice = $subtotalPrice - $localPriceDiscount;

        // Get global discount
        $globalPriceDiscount = $this->getGlobalPriceDiscount($totalPrice, $allDiscounts);
        $totalPrice -= $globalPriceDiscount; // Final $totalPrice value

        // Get shipping fee
        $shippingFee = $this->getShippingFee($totalPrice, $allDiscounts, $courierID);
        $totalPrice += $shippingFee;

        // Get Points
        $subtotalPoints = $cartItems->sum('original_subtotal_point');
        $totalPoints = $cartItems->sum('subtotal_point');

        $rawCalculation = [
            'currency' => 'HKD',
            'price' => [
                'subtotal' => number_format(max(0, $subtotalPrice), 2, '.', ''),
                'total' => number_format(max(0, $totalPrice), 2, '.', '')
            ],
            'price_discount' => [
                'local' => number_format($localPriceDiscount, 2, '.', ''),
                'global' => number_format($globalPriceDiscount, 2, '.', ''),
            ],
            'point' => [
                'subtotal' => number_format($subtotalPoints, 2, '.', ''),
                'total' => number_format($totalPoints, 2, '.', ''),
            ],
            'shipping_fee' => number_format(max(0, $shippingFee), 2, '.', '')
        ];

        // Return data
        $checkoutDetails = [
            'cart_items' => $cartItems,
            'gift_items' => $giftItems,
            'discounts' => $mappedDiscounts,
            'calculations' => $rawCalculation,
            'is_voucher_applied' => !is_null($voucherDiscount),
            'is_enough_membership_points' => $customer->isEnoughMembershipPoints($rawCalculation['point']['total'])
        ];

        // Validate Customer membership points
        $requiredPoints = $checkoutDetails['calculations']['point']['total'];
        if (!$customer->isEnoughMembershipPoints($requiredPoints)) abort(403, 'Customer does not have enough membership points for this transaction');

        // Validate, and update attributes
        $totalPrice = $checkoutDetails['calculations']['price']['total'];
        if ($totalPrice <= 0) {
            $paymentMethod = CheckoutType::OFFLINE->value;
            $isPaid = true;
        }

        // Create Order
        $orderAttributes = [
            'is_paid' => $isPaid,
            'payment_method' => $paymentMethod,
            'discounts' => $checkoutDetails['discounts'],
            'calculations' => $checkoutDetails['calculations'],
            'delivery_info' => $this->getDeliveryInfo($deliveryInfo),
            'delivery_details' => $deliveryDetails,
            'is_voucher_applied' => $checkoutDetails['is_voucher_applied'],
            'is_system' => false,
            'payment_information' => [
                'currency' => $currency,
                'conversion_rate' => $conversionRate
            ]
        ];
        $order = $customer->createOrder($orderAttributes, $store);
        $requiredPoints = $order->getTotalPoint();

        // Create OrderCartItem(s)
        $checkoutItems = collect($checkoutDetails['cart_items'])
            ->filter(function ($item) {
                return $item['is_checkout'];
            })->values();

        foreach ($checkoutItems as $item) {
            $attributes = $item->toArray();
            unset($attributes['_id'], $attributes['is_checkout']);

            // Update WarehouseInventory(s)
            $variantID = $attributes['product_variant_id'];
            $qty = $attributes['qty'];
            /** @var ProductVariant $variant */
            $variant = ProductVariant::find($variantID);
            $this->deductWarehouseInventoriesByStore(
                $store,
                $variant,
                $qty,
                WarehouseInventoryHistoryType::SALES->value,
                $customer->getUser()
            );

            $order->createCartItem($attributes);
        }

        // Create OrderGiftItem(s)
        /** @var array $item */
        foreach ($checkoutDetails['gift_items'] as $item) {
            $attributes = $item;
            unset($attributes['_id'], $attributes['is_checkout']);

            // Update WarehouseInventory(s)
            $variantID = $attributes['product_variant_id'];
            $qty = $attributes['qty'];
            /** @var ProductVariant $variant */
            $variant = ProductVariant::find($variantID);
            $this->deductWarehouseInventoriesByStore(
                $store,
                $variant,
                $qty,
                WarehouseInventoryHistoryType::SALES,
                $customer->getUser()
            );

            $order->createGiftItem($attributes);
        }

        // Update Order
        $status = Str::slug(ShipmentDeliveryStatus::SUBMITTED);
        $order->updateStatus($status);

        // Create Checkout
        $checkout = $this->createBasicCheckout($order, $paymentMethod);

        if ($order->getTotalPrice() > 0) {
            switch ($paymentMethod) {
                case CheckoutType::ONLINE->value:
                    $stripeAmount = (int) $totalPrice * 100;
                    $data = [
                        "amount" => $stripeAmount,
                        "currency" => 'HKD',
                        "captureMethod" => "automatic_async",
                        "metadata" => [
                            "model_type" => "checkout",
                            "model_id" => $checkout->_id
                        ]
                    ];

                    $url = env('PARAQON_STRIPE_BASE_URL', 'https://payment.paraqon.starsnet.hk') . '/payment-intents';
                    $res = Http::post(
                        $url,
                        $data
                    );

                    $paymentIntentID = $res['id'];
                    $clientSecret = $res['clientSecret'];

                    $checkout->update([
                        'amount' => $totalPrice,
                        'currency' => $currency,
                        'online' => [
                            'payment_intent_id' => $paymentIntentID,
                            'client_secret' => $clientSecret,
                            'api_response' => null
                        ],
                    ]);
                    // Return data
                    $data = [
                        'message' => 'Submitted Order successfully',
                        'checkout' => $checkout,
                        'order_id' => $order->_id
                    ];
                    return response()->json($data);
                case CheckoutType::OFFLINE->value:
                    $checkout->update([
                        'offline' => [
                            'image' => $request->image,
                            'uploaded_at' => now(),
                            'api_response' => null
                        ]
                    ]);
                    return [
                        'message' => 'Submitted Order successfully',
                        'checkout' => $checkout,
                        'order_id' => $order->_id
                    ];
                default:
                    return response()->json([
                        'message' => 'Invalid payment_method'
                    ], 404);
            }
        } else {
            // Distribute MembershipPoint
            $this->processMembershipPoints($customer, $order);
        }

        // Update MembershipPoint, for Offline Payment via MINI Store
        if ($paymentMethod === CheckoutType::OFFLINE && $requiredPoints > 0) {
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
            $variants = ProductVariant::objectIDs($request->checkout_product_variant_ids)->get();
            $customer->clearCartByStore($store, $variants);
        }

        // Use voucherCode
        if (!is_null($voucherCode)) {
            /** @var DiscountCode $voucher */
            $voucher = DiscountCode::where('full_code', $voucherCode)
                ->where('is_used', false)
                ->where('is_disabled', false)
                ->first();

            if (!is_null($voucher)) {
                $voucher->usedByOrder($order);
            }
        }

        // Return data
        $data = [
            'message' => 'Submitted Order successfully',
            'checkout' => $checkout,
            'order_id' => $order->_id
        ];
        return $data;
    }

    /*
    * ========================
    * Delivery Info Functions
    * =======================
    */

    private function getDeliveryInfo(array $rawInfo)
    {
        if ($rawInfo['method'] === OrderDeliveryMethod::DELIVERY) {
            $courierID = $rawInfo['courier_id'];
            /** @var Courier $courier */
            $courier = Courier::find($courierID);
            $courierInfo = [
                'title' => optional($courier)->title ?? null,
                'image' => $courier->images[0] ?? null,
            ];
            $rawInfo['courier'] = $courierInfo;
        }

        if ($rawInfo['method'] === OrderDeliveryMethod::SELF_PICKUP) {
            $warehouseID = $rawInfo['warehouse_id'];
            /** @var Warehouse $warehouse */
            $warehouse = Warehouse::find($warehouseID);
            $warehouseInfo = [
                'title' => optional($warehouse)->title ?? null,
                'image' => $warehouse->images[0] ?? null,
                'location' => $warehouse->location
            ];
            $rawInfo['warehouse'] = $warehouseInfo;
        }

        return $rawInfo;
    }

    /*
    * ===================
    * Warehouse Functions
    * ===================
    */

    private function deductWarehouseInventoriesByStore(
        Store $store,
        ProductVariant $variant,
        int $qtyChange
    ) {
        if ($qtyChange === 0) return false;

        $inventories = $this->getActiveWarehouseInventoriesByStore($store, $variant);

        $remainder = $qtyChange;

        if ($inventories->count() > 0) {
            /** @var WarehouseInventory $inventory */
            foreach ($inventories as $inventory) {
                // Terminate condition
                if ($remainder <= 0) break;

                // Get available quantity per WarehouseInventory
                $availableInventoryQty = $inventory->qty;

                // Get deductable quantity
                $deductableQty = $remainder > $availableInventoryQty ?
                    $availableInventoryQty :
                    $remainder;

                // Update WarehouseInventory
                $inventory->decrementQty($deductableQty);

                // Update remainder
                $remainder -= $deductableQty;
            }
        }

        return true;
    }

    private function getActiveWarehouseInventoriesByStore(Store $store, ProductVariant $variant)
    {
        $warehouseIDs = $store->warehouses()
            ->statusActive()
            ->pluck('id')
            ->all();

        return $variant->warehouseInventories()
            ->whereIn('warehouse_id', $warehouseIDs)
            ->orderByDesc('qty')
            ->get();
    }

    /*
    * ===================
    * Checkout Functions
    * ===================
    */

    private function createBasicCheckout(Order $order, string $paymentMethod = CheckoutType::ONLINE->value)
    {
        /** @var Checkout $checkout */
        $checkout = $order->checkout()->create(['payment_method' => $paymentMethod]);
        return $checkout;
    }

    private function updateAsOnlineCheckout(Checkout $checkout, string $successUrl, string $cancelUrl): string
    {
        /** @var Order $order */
        $order = $checkout->order;

        // Instantiate PinkiePay
        $pinkiePay = new PinkiePay($order, $successUrl, $cancelUrl);

        // Create Payment token
        $amount = $order->getTotalPrice();
        $data = $pinkiePay->createPaymentToken($amount);

        // Update Checkout
        $transactionID = $data['transaction_id'];
        Checkout::where('id', $checkout->id)->update([
            'online.transaction_id' => $transactionID
        ]);

        return $data['shortened_url'];
    }

    private function updateAsOfflineCheckout(Checkout $checkout, ?string $imageUrl): void
    {
        if (is_null($imageUrl)) return;
        $checkout->updateOfflineImage($imageUrl);
        return;
    }

    private function getValidPriceOrPercentageDiscount(
        Store $store,
        Customer $customer,
        float $price,
        int $productQty
    ): Collection {
        $customerGroups = $customer->groups()->get();

        // Get DiscountTemplate(s)
        /** @var Collection $discounts */
        $discounts = $store->discountTemplates()
            ->whereTimeAvailableNow()
            ->whereIsAutoApply()
            ->statusActive()
            ->byCustomerGroups($customerGroups)
            ->whereFulfilledMinRequirementSpending($price)
            ->whereFulfilledMinRequirementProductQty($productQty)
            ->whereDiscountTypes([
                DiscountTemplateDiscountType::PERCENTAGE->value,
                DiscountTemplateDiscountType::PRICE->value
            ])
            ->get();

        // Filter DiscountTemplate(s)
        $discounts = $this->filterValidGlobalDiscountsByCustomer($discounts, $customer);

        // Append keys
        $discounts = $discounts->map(function ($item) use ($price) {
            /** @var DiscountTemplate $item */
            $item->deducted_price = $item->calculateDeductedPrice($price);
            return $item;
        });
        $maxDeductedPrice = $discounts->max('deducted_price');

        // Find best DiscountTemplate
        $bestDiscount = $discounts->first(function ($item) use ($maxDeductedPrice) {
            return $item['deducted_price'] === $maxDeductedPrice;
        });

        return collect([$bestDiscount]);
    }

    private function getAllValidBuyXGetYFreeDiscounts(
        Store $store,
        Customer $customer,
        float $price,
        int $productQty,
        Collection $cartItems
    ): Collection {
        $customerGroups = $customer->groups()->get();
        $checkoutProductVariantIDs = $cartItems->pluck('product_variant_id')->all();
        if (count($checkoutProductVariantIDs) === 0) return collect();

        /** @var Collection $discounts */
        $discounts = $store->discountTemplates()
            ->whereTimeAvailableNow()
            ->whereIsAutoApply()
            ->statusActive()
            ->byCustomerGroups($customerGroups)
            ->whereFulfilledMinRequirementSpending($price)
            ->whereFulfilledMinRequirementProductQty($productQty)
            ->whereDiscountType(DiscountTemplateDiscountType::BUY_X_GET_Y_FREE->value)
            ->whereProductVariantXIDs($checkoutProductVariantIDs)
            ->get();

        // Filter DiscountTemplate(s)
        $discounts = $this->filterValidGlobalDiscountsByCustomer($discounts, $customer);

        $discounts = $discounts->filter(function ($discount) use ($cartItems) {
            // Extract attributes from matched $cartItem
            $variantXID = $discount->x['product_variant_id'];
            $matchingCartItem = $cartItems->firstWhere('product_variant_id', $variantXID);
            if (is_null($matchingCartItem)) return false;
            $checkoutQty = $matchingCartItem['qty'];

            // Calculate gift qty
            $giftYQty = floor($checkoutQty / intval($discount->x['qty']) * intval($discount->y['qty']));
            if ($giftYQty < 1) return false;
            return true;
        });

        return $discounts;
    }

    private function getFreeShippingDiscounts(
        Store $store,
        Customer $customer,
        float $price,
        int $productQty
    ): Collection {
        $customerGroups = $customer->groups()->get();

        // Get DiscountTemplate(s)
        /** @var Collection $discounts */
        $discounts = $store->discountTemplates()
            ->whereTimeAvailableNow()
            ->whereIsAutoApply()
            ->statusActive()
            ->byCustomerGroups($customerGroups)
            ->whereFulfilledMinRequirementSpending($price)
            ->whereFulfilledMinRequirementProductQty($productQty)
            ->whereTemplateType(DiscountTemplateType::FREE_SHIPPING)
            ->latest()
            ->get();

        // Filter DiscountTemplate(s)
        $discounts = $this->filterValidGlobalDiscountsByCustomer($discounts, $customer);

        // Find latest discount
        $discount = $discounts->first();

        return collect([$discount]);
    }

    private function getVoucherDiscount(
        ?string $voucherCode = null,
        Store $store,
        Customer $customer,
        float $price,
        int $productQty
    ): ?Collection {
        // Validate parameters
        if (is_null($voucherCode)) return null;

        // Get DiscountCode (voucher), then validate
        /** @var DiscountCode $voucher */
        $voucher = DiscountCode::where('full_code', $voucherCode)
            ->where('is_used', false)
            ->where('is_disabled', false)
            ->first();

        /** @var DiscountTemplate $voucher */
        if (is_null($voucher)) {
            $customerGroups = $customer->groups()->get();
            $template = DiscountTemplate::wherePrefix($voucherCode)
                ->whereTimeAvailableNow()
                ->whereIsAutoApply(false)
                ->statusActive()
                ->byCustomerGroups($customerGroups)
                ->whereFulfilledMinRequirementSpending($price)
                ->whereFulfilledMinRequirementProductQty($productQty)
                ->whereTemplateTypes([
                    DiscountTemplateType::PROMOTION_CODE,
                    DiscountTemplateType::FREE_SHIPPING
                ])
                ->latest()
                ->first();
            if (is_null($template)) return null;
        } else {
            $template = $voucher->discountTemplate;
        }

        /** @var DiscountTemplate $template */
        if (!$template->canCustomerApply($customer)) return null;
        return collect([$template]);
    }

    private function filterValidGlobalDiscountsByCustomer(Collection $discounts, Customer $customer): Collection
    {
        $filteredDiscounts = $discounts->filter(function ($discount) use ($customer) {
            /** @var DiscountTemplate $discount */
            if (!$discount->isEnoughQuota()) return false;

            // Validate quota_per_customer
            $quota = $discount['quota_per_customer'];
            $code = $discount['prefix'];
            $orderCount = $customer->orders()->whereDiscountsContainFullCode($code)->count();
            if (!is_null($quota) && $quota <= $orderCount) return false;

            return true;
        });

        return $filteredDiscounts;
    }

    private function getGiftItems(Collection $cartItems, Collection $discounts): array
    {
        // Validate parameters
        if ($cartItems->count() === 0) return array();
        if ($discounts->count() === 0) return array();

        // Filter discounts with matching discount_type of BUY_X_GET_Y_FREE
        $discounts = $discounts->filter(function ($item) {
            return $item->discount_type === DiscountTemplateDiscountType::BUY_X_GET_Y_FREE->value;
        });

        $giftItems = [];
        /** @var DiscountTemplate $discount */
        foreach ($discounts as $discount) {
            // Extract attributes from $discount
            $variantXID = $discount->x['product_variant_id'];
            $variantYID = $discount->y['product_variant_id'];

            // Get ProductVariant(s), then validate
            /** @var ProductVariant $variantX */
            $variantX = ProductVariant::find($variantXID);
            /** @var ProductVariant $variantY */
            $variantY = ProductVariant::find($variantYID);
            if (is_null($variantX) || is_null($variantY)) continue;

            $matchingCartItem = $cartItems->firstWhere('product_variant_id', $variantXID);
            if (is_null($matchingCartItem)) continue;
            $checkoutQty = $matchingCartItem['qty'];

            // Calculate gift qty
            $giftYQty = floor($checkoutQty / intval($discount->x['qty']) * intval($discount->y['qty']));
            if ($giftYQty < 1) continue;

            // Update $giftItems
            $giftItem = $this->constructGiftItem($variantY, $giftYQty);
            $giftItems[] = $giftItem;

            // Update global_discount key 
            $globalDiscount = $this->constructGlobalDiscount($variantX, $variantY, $discount);
            $matchingCartItem['global_discount'] = $globalDiscount;
        }

        return $giftItems;
    }

    private function constructGiftItem(ProductVariant $variant, int $qty): array
    {
        $product = $variant->product;

        $item = [
            '_id' => null,
            'product_variant_id' => $variant->_id,
            'qty' => $qty,
            'product_title' => $product->title,
            'product_variant_title' => $variant->title,
            'short_description' => $variant->short_description ?? $product->short_description,
            'image' => $variant->images[0] ?? $product->images[0] ?? null,
            'original_price_per_unit' => 0,
            'discounted_price_per_unit' => 0,
            'original_subtotal_price' => 0,
            'subtotal_price' => 0,
            'original_point_per_unit' => 0,
            'discounted_point_per_unit' => 0,
            'original_subtotal_point' => 0,
            'subtotal_point' => 0,
        ];

        return $item;
    }

    private function constructGlobalDiscount(ProductVariant $variantX, ProductVariant $variantY, DiscountTemplate $discount)
    {
        $globalDiscount = [
            'type' => DiscountTemplateDiscountType::BUY_X_GET_Y_FREE,
            'x' => [
                'product_variant_id' => $variantX->_id,
                'qty' => $discount->x['qty'],
                'product_title' => $variantX->title
            ],
            'y' => [
                'product_variant_id' => $variantY->_id,
                'qty' => $discount->y['qty'],
                'product_title' => $variantY->title
            ]
        ];

        return $globalDiscount;
    }

    private function getGlobalPriceDiscount($price, Collection $discounts)
    {
        if ($discounts->count() === 0) return 0;

        // Filter discounts with PRICE and PERCENTAGE discount_type only
        $discounts = $discounts->filter(function ($item) {
            return in_array($item->discount_type, [
                DiscountTemplateDiscountType::PRICE->value,
                DiscountTemplateDiscountType::PERCENTAGE->value
            ]);
        })
            ->sortByDesc('discount_value');

        // Calculate globalPriceDiscount
        $totalDeductedPrice = 0;
        /** @var DiscountTemplate $discount */
        foreach ($discounts as $discount) {
            $deductedPrice = 0;
            switch ($discount->discount_type) {
                case DiscountTemplateDiscountType::PRICE->value:
                    $deductedPrice = $discount->discount_value;
                    break;
                case DiscountTemplateDiscountType::PERCENTAGE->value:
                    $deductedPrice = $price * (($discount->discount_value) / 100);
                    break;
                default:
                    break;
            }
            $totalDeductedPrice += $deductedPrice;
            $price -= $deductedPrice;
        }

        return $totalDeductedPrice;
    }

    private function getShippingFee($price, Collection $applicableDiscounts, ?string $courierID = null)
    {
        if (is_null($courierID)) return 0;

        $discount = $applicableDiscounts->first(function ($item) {
            return $item->template_type === DiscountTemplateType::FREE_SHIPPING->value;
        });
        if (!is_null($discount)) return 0;

        /** @var Courier $courier */
        $courier = Courier::find($courierID);
        return !is_null($courier) ?
            $courier->getShippingFeeByTotalFee($price) :
            0;
    }
}
