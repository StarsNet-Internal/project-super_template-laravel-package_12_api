<?php

namespace Starsnet\Project\Auction\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

// Traits
use App\Http\Controllers\Traits\ShoppingCartTrait;

// Enums
use App\Enums\CheckoutType;
use App\Enums\OrderDeliveryMethod;
use App\Enums\ShipmentDeliveryStatus;
use App\Enums\Status;

// Models
use App\Models\Alias;
use App\Models\Checkout;
use App\Models\Courier;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShoppingCartItem;
use App\Models\Store;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;

class ShoppingCartController extends Controller
{
    use ShoppingCartTrait;

    /** @var Store|null */
    protected $store;

    private function getStore(string $storeID): ?Store
    {
        return Store::find($storeID) ?? Store::find(Alias::getValue($storeID));
    }

    public function getAll(Request $request): array
    {
        $now = now();

        $this->store = $this->getStore($request->route('store_id'));

        // Get authenticated User information
        $customer = $this->customer();

        // Remove ShoppingCartItem(s) if variant is not ACTIVE status
        $customer->shoppingCartItems()
            ->where('store_id', $this->store->id)
            ->whereHas('productVariant', fn($q) => $q->where('status', '!=', Status::ACTIVE->value))
            ->delete();

        // Extract attributes from $request
        $checkoutVariantIDs = $request->checkout_product_variant_ids;
        $voucherCode = $request->voucher_code;
        $deliveryInfo = $request->delivery_info;

        $courierID = $deliveryInfo['method'] === OrderDeliveryMethod::DELIVERY->value ?
            $deliveryInfo['courier_id'] :
            null;

        // Get ShoppingCartItem data
        $cartItems = $this->getCartItems($customer, $checkoutVariantIDs ?? []);
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

        // Append inventory_count per cart item (sum of qty from WarehouseInventory by product_variant_id)
        $variantIds = $cartItems->pluck('product_variant_id')->unique()->filter()->values()->all();
        $inventoryCounts = collect();
        if (!empty($variantIds)) {
            $inventoryCounts = WarehouseInventory::whereIn('product_variant_id', $variantIds)
                ->get()
                ->groupBy('product_variant_id')
                ->map(fn($group) => $group->sum(fn($r) => (int) $r->qty));
        }
        $cartItems->each(function ($item) use ($inventoryCounts) {
            $item->inventory_count = $inventoryCounts[$item->product_variant_id] ?? 0;
        });

        // credit_card_charge_percentage
        $totalPrice = (float) $calculation['price']['total'];
        $creditCardChargePercentage = (float) $request->input('credit_card_charge_percentage', 0);
        $creditCardChargeFee = floor(($totalPrice * $creditCardChargePercentage) / 100);
        $calculation['price']['total'] = number_format($totalPrice + $creditCardChargeFee, 2, '.', '');
        $calculation['credit_card_charge_fee'] = number_format($creditCardChargeFee, 2, '.', '');

        return [
            'cart_items' => $cartItems,
            'gift_items' => $giftItems,
            'discounts' => $this->formatDiscounts($discounts),
            'calculations' => $calculation,
            'is_voucher_applied' => !is_null($discounts['voucher']),
            'is_enough_membership_points' => $isEnoughMembershipPoints
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
            ]);

        // Get product_id => owned_by_customer_id mapping without loading full products
        $productIds = $cartItems->pluck('product_id')->unique()->filter()->values()->all();
        $sellerIdMap = [];
        if (!empty($productIds)) {
            $sellerIdMap = Product::whereIn('id', $productIds)
                ->get(['id', 'owned_by_customer_id'])
                ->pluck('owned_by_customer_id', 'id')
                ->toArray();
        }

        $cartItems->each(function ($item) use ($checkoutVariantIDs, $sellerIdMap) {
            $item->is_checkout = in_array($item->product_variant_id, $checkoutVariantIDs);
            $item->is_refundable = false;
            $item->global_discount = null;
            $item->seller_id = $sellerIdMap[$item->product_id] ?? null;
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

        // credit_card_charge_percentage
        $creditCardChargePercentage = (float) ($request->json('credit_card_charge_percentage') ?? $request->input('credit_card_charge_percentage', 0));
        $creditCardChargeFee = (int) floor(($totalPrice * $creditCardChargePercentage) / 100);
        $totalPrice = $totalPrice + $creditCardChargeFee;

        $rawCalculation = [
            'currency' => 'HKD',
            'price' => [
                'subtotal' => $subtotalPrice,
                'total' => number_format($totalPrice, 2, '.', ''), // Deduct price_discount.local and .global
            ],
            'price_discount' => [
                'local' => '0.00',
                'global' => '0.00',
            ],
            'point' => [
                'subtotal' => '0.00',
                'total' => '0.00',
            ],
            'credit_card_charge_fee' => number_format($creditCardChargeFee, 2, '.', ''),
            'shipping_fee' => $shippingFee,
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
                $qty
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
                    $stripeAmount = (int) round((float) $totalPrice * 100);

                    $data = [
                        'amount' => $stripeAmount,
                        'currency' => 'HKD',
                        'captureMethod' => 'automatic_async',
                        'metadata' => [
                            'model_type' => 'checkout',
                            'model_id' => $checkout->_id,
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

    private function getDeliveryInfo(array $rawInfo)
    {
        $method = $rawInfo['method'] ?? null;
        $methodValue = $method instanceof \BackedEnum ? $method->value : $method;

        if ($methodValue === OrderDeliveryMethod::DELIVERY->value) {
            $courierID = $rawInfo['courier_id'];
            /** @var Courier $courier */
            $courier = Courier::find($courierID);
            $courierInfo = [
                'title' => optional($courier)->title ?? null,
                'image' => $courier->images[0] ?? null,
            ];
            $rawInfo['courier'] = $courierInfo;
        }

        if ($methodValue === OrderDeliveryMethod::SELF_PICKUP->value) {
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

    private function deductWarehouseInventoriesByStore(
        Store $store,
        ProductVariant $variant,
        int $qtyChange
    ) {
        if ($qtyChange === 0) return false;

        $inventories = $this->getActiveWarehouseInventoriesByStore($store, $variant);

        $remainder = $qtyChange;

        if ($inventories->count() > 0) {
            foreach ($inventories as $inventory) {
                if ($remainder <= 0) break;

                $availableInventoryQty = $inventory->qty;
                $deductableQty = $remainder > $availableInventoryQty ?
                    $availableInventoryQty :
                    $remainder;

                $inventory->decrementQty($deductableQty);
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

    private function createBasicCheckout(Order $order, string $paymentMethod = CheckoutType::ONLINE->value)
    {
        /** @var Checkout $checkout */
        $checkout = $order->checkout()->create(['payment_method' => $paymentMethod]);
        return $checkout;
    }
}
