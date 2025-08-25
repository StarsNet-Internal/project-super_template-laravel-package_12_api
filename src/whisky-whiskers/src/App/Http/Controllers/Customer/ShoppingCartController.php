<?php

namespace Starsnet\Project\WhiskyWhiskers\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// Enums
use App\Enums\CheckoutType;
use App\Enums\OrderDeliveryMethod;
use App\Enums\ShipmentDeliveryStatus;

// Services
use App\Http\Starsnet\PinkiePay;

// Models
use App\Models\Alias;
use App\Models\Checkout;
use App\Models\Courier;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\ShoppingCartItem;
use App\Models\Store;
use App\Models\Warehouse;
use Starsnet\Project\WhiskyWhiskers\App\Models\AuctionLot;

class ShoppingCartController extends Controller
{
    /** @var Store $store */
    protected $store;

    public function __construct(Request $request)
    {
        $this->store = Store::find($request->route('store_id'))
            ?? Store::find(Alias::getValue($request->route('store_id')));
    }

    public function getAllAuctionCartItems(Request $request)
    {
        // Get authenticated User information
        $customer = $this->customer();
        ShoppingCartItem::where('customer_id', $customer->id)
            ->where('store_id', $this->store->id)
            ->delete();

        // Winning auction lots by Customer
        $wonLots = AuctionLot::where('store_id', $this->store->_id)
            ->where('winning_bid_customer_id', $customer->_id)
            ->get();

        foreach ($wonLots as $lot) {
            $attributes = [
                'store_id' => $this->store->id,
                'product_id' => $lot->product_id,
                'product_variant_id' => $lot->product_variant_id,
                'qty' => 1,
                'winning_bid' => $lot->current_bid,
                'storage_fee' => $lot->current_bid * 0.03
            ];
            $customer->shoppingCartItems()->create($attributes);
        }

        // Get ShoppingCartItem(s)
        $cartItems = ShoppingCartItem::where('customer_id', $customer->id)
            ->where('store_id', $this->store->id)
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

        // Extract attributes from $request
        $isStorage = $request->boolean('is_storage', false);
        $deliveryInfo = $request->delivery_info;

        $courierID = $deliveryInfo['method'] === OrderDeliveryMethod::DELIVERY->value ?
            $deliveryInfo['courier_id'] :
            null;

        // getShoppingCartDetails calculations
        // get subtotal Price
        $subtotalPrice = 0;
        $storageFee = 0;

        $SERVICE_CHARGE_MULTIPLIER = 0.1;
        $totalServiceCharge = 0;

        foreach ($cartItems as $item) {
            // Calculations
            $winningBid = $item->winning_bid ?? 0;
            $subtotalPrice += $winningBid;

            // Service Charge
            $totalServiceCharge += $winningBid *
                $SERVICE_CHARGE_MULTIPLIER;

            if ($isStorage == true) {
                $storageFee += $item->storage_fee ?? 0;
            }
        }
        $totalPrice = $subtotalPrice + $storageFee + $totalServiceCharge;

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
                'total' => number_format(floor($totalPrice), 2, '.', ''),
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

    public function getAllMainStoreCartItems(Request $request)
    {
        // Extract attributes from $request
        $deliveryInfo = $request->delivery_info;

        // Get authenticated User information
        $customer = $this->customer();

        // Winning auction lots by Customer
        $selectedItems = ShoppingCartItem::where('store_id', $this->store->id)->get();

        foreach ($selectedItems as $item) {
            $item->update([
                'winning_bid' => 0,
                'storage_fee' => 0
            ]);
        }

        // Get ShoppingCartItem(s)
        $cartItems = ShoppingCartItem::where('customer_id', $customer->id)
            ->where('store_id', $this->store->id)
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

        // Extract attributes from $request
        $deliveryInfo = $request->delivery_info;

        $courierID = $deliveryInfo['method'] === OrderDeliveryMethod::DELIVERY ?
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
                'total' => number_format(floor($totalPrice), 2, '.', ''),
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

        // Get authenticated User information
        $customer = $this->customer();

        // Get ShoppingCartItem(s)
        $cartItems = ShoppingCartItem::where('customer_id', $customer->id)
            ->where('store_id', $this->store->id)
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

        // Extract attributes from $request
        $deliveryInfo = $request->delivery_info;
        $deliveryDetails = $request->delivery_details;
        $paymentMethod = $request->payment_method;
        $successUrl = $request->success_url;
        $cancelUrl = $request->cancel_url;

        $isStorage = $request->boolean('is_storage', false);

        $courierID = $deliveryInfo['method'] === OrderDeliveryMethod::DELIVERY ?
            $deliveryInfo['courier_id'] :
            null;

        // getShoppingCartDetails calculations
        // get subtotal Price
        $subtotalPrice = 0;
        $storageFee = 0;

        $SERVICE_CHARGE_MULTIPLIER = 0.1;
        $totalServiceCharge = 0;

        foreach ($cartItems as $item) {
            // Calculations
            $winningBid = $item->winning_bid ?? 0;
            $subtotalPrice += $winningBid;

            // Service Charge
            $totalServiceCharge += $winningBid *
                $SERVICE_CHARGE_MULTIPLIER;

            if ($isStorage == true) {
                $storageFee += $item->storage_fee ?? 0;
            } else {
                $item->storage_fee == 0;
            }
        }
        $totalPrice = $subtotalPrice +
            $storageFee + $totalServiceCharge;

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
                'total' => number_format(floor($totalPrice), 2, '.', ''),
            ],
            'price_discount' => [
                'local' => 0,
                'global' => 0,
            ],
            'point' => [
                'subtotal' => 0,
                'total' => 0,
            ],
            'service_charge' => number_format($totalServiceCharge, 2, '.', ''),
            'storage_fee' => number_format($storageFee, 2, '.', ''),
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
        if ($totalPrice <= 0) $paymentMethod = CheckoutType::OFFLINE->value;

        // Create Order
        $order = Order::create([
            'customer_id' => $customer->id,
            'store_id' => $this->store->id,
            'is_paid' => $request->input('is_paid', false),
            'payment_method' => $paymentMethod,
            'discounts' => $checkoutDetails['discounts'],
            'calculations' => $checkoutDetails['calculations'],
            'delivery_info' => $this->getDeliveryInfo($deliveryInfo),
            'delivery_details' => $deliveryDetails,
            'is_voucher_applied' => $checkoutDetails['is_voucher_applied'],
            'paid_order_id' => null,
            'is_storage' => $isStorage
        ]);

        // Create OrderCartItem(s)
        $checkoutItems = collect($checkoutDetails['cart_items'])
            ->filter(function ($item) {
                return $item->is_checkout;
            })->values();

        foreach ($checkoutItems as $item) {
            $attributes = $item->toArray();
            unset($attributes['_id'], $attributes['is_checkout']);
            $order->createCartItem($attributes);
        }

        // Update Order
        $status = Str::slug(ShipmentDeliveryStatus::SUBMITTED->value);
        $order->updateStatus($status);

        // Create Checkout
        $checkout = $order->checkout()->create([
            'payment_method' => $paymentMethod
        ]);

        $returnUrl = null;
        if ($order->getTotalPrice() > 0) {
            switch ($paymentMethod) {
                case CheckoutType::ONLINE->value:
                    $pinkiePay = new PinkiePay($order, $successUrl, $cancelUrl);
                    $data = $pinkiePay->createPaymentToken();
                    $returnUrl = $data['shortened_url'];
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
                    return ['message' => 'Invalid payment_method'];
            }
        }

        return [
            'message' => 'Submitted Order successfully',
            'return_url' => $returnUrl ?? null,
            'order_id' => $order->_id
        ];
    }

    public function checkOutMainStore(Request $request)
    {
        $now = now();

        // Get authenticated User information
        $customer = $this->customer();

        // Get ShoppingCartItem(s)
        $cartItems = ShoppingCartItem::where('customer_id', $customer->id)
            ->where('store_id', $this->store->id)
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

        // Extract attributes from $request
        $deliveryInfo = $request->delivery_info;
        $deliveryDetails = $request->delivery_details;
        $paymentMethod = $request->payment_method;
        $successUrl = $request->success_url;
        $cancelUrl = $request->cancel_url;

        $courierID = $deliveryInfo['method'] === OrderDeliveryMethod::DELIVERY ?
            $deliveryInfo['courier_id'] :
            null;

        // getShoppingCartDetails calculations
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
                'total' => number_format($totalPrice, 2, '.', ''),
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

        // Return data
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
        $orderAttributes = [
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'is_paid' => $request->input('is_paid', false),
            'payment_method' => $paymentMethod,
            'discounts' => $checkoutDetails['discounts'],
            'calculations' => $checkoutDetails['calculations'],
            'delivery_info' => $this->getDeliveryInfo($deliveryInfo),
            'delivery_details' => $deliveryDetails,
            'is_voucher_applied' => $checkoutDetails['is_voucher_applied'],
        ];
        $order = Order::create($orderAttributes);

        // Create OrderCartItem(s)
        $checkoutItems = collect($checkoutDetails['cart_items'])
            ->filter(function ($item) {
                return $item->is_checkout;
            })->values();

        foreach ($checkoutItems as $item) {
            $attributes = $item->toArray();
            unset($attributes['_id'], $attributes['is_checkout']);
            $order->createCartItem($attributes);
        }

        // Update Order
        $status = Str::slug(ShipmentDeliveryStatus::SUBMITTED->value);
        $order->updateStatus($status);

        // Create Checkout
        $checkout = $order->checkout()->create([
            'payment_method' => $paymentMethod
        ]);

        $returnUrl = null;
        if ($order->getTotalPrice() > 0) {
            switch ($paymentMethod) {
                case CheckoutType::ONLINE->value:
                    $pinkiePay = new PinkiePay($order, $successUrl, $cancelUrl);
                    $data = $pinkiePay->createPaymentToken();
                    $returnUrl = $data['shortened_url'];
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
                    return response()->json([
                        'message' => 'Invalid payment_method'
                    ], 404);
            }
        }

        if ($paymentMethod === CheckoutType::OFFLINE->value) {
            // Delete ShoppingCartItem(s)
            $variants = ProductVariant::whereIn('_id', $request->checkout_product_variant_ids)->get();
            ShoppingCartItem::where('customer_id', $customer->id)
                ->where('store_id', $this->store->id)
                ->whereIn('product_variant_id', $request->checkout_product_variant_ids)
                ->delete();

            // Update product
            foreach ($variants as $variant) {
                $product = $variant->product;
                $product->update([
                    'listing_status' => 'ALREADY_CHECKOUT'
                ]);
            }
        }

        return [
            'message' => 'Submitted Order successfully',
            'return_url' => $returnUrl ?? null,
            'order_id' => $order->_id
        ];
    }

    /*
    * ========================
    * Delivery Info Functions
    * =======================
    */

    private function getDeliveryInfo(array $rawInfo)
    {
        if ($rawInfo['method'] === OrderDeliveryMethod::DELIVERY->value) {
            $courierID = $rawInfo['courier_id'];
            /** @var Courier $courier */
            $courier = Courier::find($courierID);
            $courierInfo = [
                'title' => optional($courier)->title ?? null,
                'image' => $courier->images[0] ?? null,
            ];
            $rawInfo['courier'] = $courierInfo;
        }

        if ($rawInfo['method'] === OrderDeliveryMethod::SELF_PICKUP->value) {
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
}
