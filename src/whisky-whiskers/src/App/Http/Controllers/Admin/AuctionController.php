<?php

namespace Starsnet\Project\WhiskyWhiskers\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

// Enums
use App\Enums\CheckoutType;
use App\Enums\OrderPaymentMethod;
use App\Enums\ShipmentDeliveryStatus;
use App\Enums\Status;

// Models
use App\Models\Product;
use App\Models\Store;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ShoppingCartItem;
use Starsnet\Project\WhiskyWhiskers\App\Models\AuctionLot;

class AuctionController extends Controller
{
    public function updateAuctionStatuses(): array
    {
        $now = now();

        // Make stores ACTIVE
        $archivedStores = Store::where('type', 'OFFLINE')
            ->where('status', Status::ARCHIVED->value)
            ->get();

        $archivedStoresUpdateCount = 0;
        foreach ($archivedStores as $store) {
            $startTime = Carbon::parse($store->start_datetime);
            $endTime = Carbon::parse($store->end_datetime);

            if ($now >= $startTime && $now < $endTime) {
                $store->update(['status' => Status::ACTIVE->value]);

                // Update all AuctionLots as ACTIVE status
                $storeID = $store->_id;
                AuctionLot::where('store_id', $storeID)
                    ->where('status', Status::ARCHIVED->value)
                    ->update(['status' => Status::ACTIVE->value]);
                $archivedStoresUpdateCount++;
            }
        }

        // Make stores ARCHIVED
        $activeStores = Store::where('type', 'OFFLINE')
            ->where('status', Status::ACTIVE->value)
            ->get();

        $activeStoresUpdateCount = 0;
        foreach ($activeStores as $store) {
            $endTime = Carbon::parse($store->end_datetime);

            if ($now >= $endTime) {
                $store->update(['status' => Status::ARCHIVED->value]);
                $activeStoresUpdateCount++;
            }
        }

        return [
            'now_time' => $now,
            'message' => "Updated {$archivedStoresUpdateCount} Auction(s) as ACTIVE, and {$activeStoresUpdateCount} Auction(s) as ARCHIVED"
        ];
    }

    public function archiveAllAuctionLots(Request $request): array
    {
        $storeID = $request->route('store_id');
        $store = Store::find($storeID);
        if (is_null($store)) abort(404, 'Store not found');

        $allAuctionLots = AuctionLot::where('store_id', $storeID)
            ->where('status', Status::ACTIVE->value)
            ->get();

        foreach ($allAuctionLots as $lot) {
            try {
                $isBidPlaced = $lot->is_bid_placed;
                $currentBid = $lot->getCurrentBidPrice();

                $product = Product::find($lot->product_id);
                // Reject auction lot
                if ($isBidPlaced == false || $lot->reserve_price > $currentBid) {
                    // Update Product
                    $product->update(['listing_status' => 'AVAILABLE']);

                    // Update Auction Lot
                    $lot->update([
                        'winning_bid_customer_id' => null,
                        'current_bid' => $currentBid,
                        'status' => Status::ARCHIVED->value,
                        'is_paid' => false
                    ]);

                    // Create Passed Auction
                    $lot->passedAuctionRecords()->create([
                        'customer_id' => $lot->owned_by_customer_id,
                        'product_id' => $lot->product_id,
                        'product_variant_id' => $lot->product_variant_id,
                        'auction_lot_id' => $lot->_id,
                        'remarks' => 'Not met reserved price'
                    ]);
                } else {
                    // Get final highest bidder info
                    $allBids = $lot->bids()
                        ->where('is_hidden', false)
                        ->get();
                    $highestBidValue = $allBids->pluck('bid')->max();
                    $higestBidderCustomerID = $lot->bids()
                        ->where('bid', $highestBidValue)
                        ->orderBy('created_at')
                        ->first()
                        ->customer_id;

                    // Update lot
                    $lot->update([
                        'winning_bid_customer_id' => $higestBidderCustomerID,
                        'current_bid' => $currentBid,
                        'status' => Status::ARCHIVED->value,
                    ]);
                }
            } catch (\Throwable $th) {
                print($th);
            }
        }

        $store->update(["remarks" => "SUCCESS"]);

        return [
            'message' => 'Archived Store Successfully, updated ' . $allAuctionLots->count() . ' Auction Lots.'
        ];
    }

    public function generateAuctionOrders(Request $request): array
    {
        // Get Store
        $storeID = $request->route('store_id');
        $store = Store::find($storeID);
        if (is_null($store)) abort(404, 'Store not found');

        // Get Auction Lots
        $unpaidAuctionLots = AuctionLot::where('store_id', $storeID)
            ->where('status', Status::ARCHIVED->value)
            ->whereNotNull('winning_bid_customer_id')
            ->get();

        // Get unique winning_bid_customer_id
        $winningCustomerIDs = $unpaidAuctionLots->pluck('winning_bid_customer_id')
            ->unique()
            ->values()
            ->all();

        // Generate OFFLINE order by system
        $generatedOrderCount = 0;
        foreach ($winningCustomerIDs as $customerID) {
            try {
                // Find all winning Auction Lots
                $winningLots = $unpaidAuctionLots->where('winning_bid_customer_id', $customerID)->all();

                // Add item to Customer's Shopping Cart, with calculated winning_bid + storage_fee
                $customer = Customer::find($customerID);
                foreach ($winningLots as $lot) {
                    $attributes = [
                        'store_id' => $storeID,
                        'product_id' => $lot->product_id,
                        'product_variant_id' => $lot->product_variant_id,
                        'qty' => 1,
                        'winning_bid' => $lot->current_bid,
                        'storage_fee' => $lot->current_bid * 0.03
                    ];
                    $customer->shoppingCartItems()->create($attributes);
                }

                // Get ShoppingCartItem(s)
                $cartItems = $customer->where('store_id', $store->id)
                    ->where('customer_id', $customer->id)
                    ->get();

                // Start Shopping Cart calculations
                // Get subtotal Price
                $subtotalPrice = 0;
                $storageFee = 0;

                $SERVICE_CHARGE_MULTIPLIER = 0.1;
                $totalServiceCharge = 0;

                foreach ($cartItems as $item) {
                    // Add keys
                    $item->is_checkout = true;
                    $item->is_refundable = false;
                    $item->global_discount = null;

                    // Get winning_bid, update subtotal_price
                    $winningBid = $item->winning_bid ?? 0;
                    $subtotalPrice += $winningBid;

                    // Update total_service_charge
                    $totalServiceCharge += $winningBid *
                        $SERVICE_CHARGE_MULTIPLIER;
                }
                $totalPrice = $subtotalPrice +
                    $storageFee + $totalServiceCharge;

                // Get shipping_fee, then update total_price
                $shippingFee = 0;
                $totalPrice += $shippingFee;

                // Form calculation data object
                $rawCalculation = [
                    'currency' => 'HKD',
                    'price' => [
                        'subtotal' => number_format($subtotalPrice, 2, '.', ''),
                        'total' => number_format(ceil($totalPrice), 2, '.', ''),
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
                $paymentMethod = CheckoutType::OFFLINE->value;

                // Create Order
                $orderAttributes = [
                    'is_paid' => $request->input('is_paid', false),
                    'payment_method' => $paymentMethod,
                    'discounts' => $checkoutDetails['discounts'],
                    'calculations' => $checkoutDetails['calculations'],
                    'delivery_info' => [
                        'country_code' => 'HK',
                        'method' => 'FACE_TO_FACE_PICKUP',
                        'courier_id' => null,
                        'warehouse_id' => null,
                    ],
                    'delivery_details' => [
                        'recipient_name' => null,
                        'email' => null,
                        'area_code' => null,
                        'phone' => null,
                        'address' => null,
                        'remarks' => null,
                    ],
                    'is_voucher_applied' => $checkoutDetails['is_voucher_applied'],
                    'paid_order_id' => null,
                    'is_storage' => false
                ];
                $order = $customer->createOrder($orderAttributes, $store);

                // Create OrderCartItem(s)
                $checkoutItems = collect($checkoutDetails['cart_items'])
                    ->filter(function ($item) {
                        return $item->is_checkout;
                    })->values();

                $variantIDs = [];
                foreach ($checkoutItems as $item) {
                    $attributes = $item->toArray();
                    unset($attributes['_id'], $attributes['is_checkout']);
                    $variantID = $attributes['product_variant_id'];
                    $variantIDs[] = $variantID;
                    $order->createCartItem($attributes);
                }

                // Update Order
                $status = Str::slug(ShipmentDeliveryStatus::SUBMITTED->value);
                $order->updateStatus($status);

                // Create Checkout
                $order->checkout()->create(['payment_method' => $paymentMethod]);

                // Delete ShoppingCartItem(s)
                ShoppingCartItem::where('customer_id', $customer->id)
                    ->where('store_id', $store->id)
                    ->whereIn('product_variant_id', $variantIDs)
                    ->delete();

                $generatedOrderCount++;
            } catch (\Throwable $th) {
                print($th);
            }
        }

        return [
            'message' => "Generated All {$generatedOrderCount} Auction Store Orders Successfully"
        ];
    }

    public function getAllUnpaidAuctionLots(Request $request): Collection
    {
        return AuctionLot::where('store_id', $request->route('store_id'))
            ->whereNotNull('winning_bid_customer_id')
            ->where('is_paid', false)
            ->with([
                'product',
                'store',
            ])
            ->get();
    }

    public function returnAuctionLotToOriginalCustomer(Request $request): array
    {
        /** @var ?Order $order */
        $order = Order::find($request->input('id'));
        if (is_null($order))  abort(404, 'Order not found');
        if ($order->payment_method != OrderPaymentMethod::OFFLINE->value) abort(404, 'Order is an Online Payment Order, items cannot be returned');

        $variantIDs = collect($order->cart_items)->pluck('product_variant_id')->all();
        $unpaidAuctionLots = AuctionLot::where('store_id', $order->store_id)
            ->whereIn('product_variant_id', $variantIDs)
            ->where('is_paid', false)
            ->get();

        foreach ($unpaidAuctionLots as $lot) {
            $lot->update(["winning_bid_customer_id" => null]);
        }

        // Update Product status, and reset AuctionLot WinningCustomer
        Product::whereIn($unpaidAuctionLots->pluck('product_id')->all())
            ->update(['listing_status' => 'AVAILABLE']);

        return [
            'message' => 'Updated listing_status for Product(s).'
        ];
    }

    public function getAllAuctionLotsByStore(Request $request): Collection
    {
        /** @var Collection $lots */
        $lots = AuctionLot::where('store_id', $request->route('store_id'))
            ->where('status', '!=', Status::DELETED->value)
            ->with([
                'product',
                'winningBidCustomer',
                'winningBidCustomer.account',
            ])
            ->get();

        foreach ($lots as $lot) {
            $lot->current_bid = $lot->getCurrentBidPrice();
        }

        return $lots;
    }
}
