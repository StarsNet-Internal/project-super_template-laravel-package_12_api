<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

// Enums
use App\Enums\Status;
use App\Enums\CheckoutType;
use App\Enums\OrderPaymentMethod;
use App\Enums\ReplyStatus;
use App\Enums\ShipmentDeliveryStatus;

// Models
use App\Models\Checkout;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ShoppingCartItem;
use App\Models\Store;
use Starsnet\Project\Paraqon\App\Models\AuctionLot;
use Starsnet\Project\Paraqon\App\Models\AuctionRegistrationRequest;

class AuctionController extends Controller
{
    public function syncCategoriesToProduct(Request $request): array
    {
        /** @var ?Product $product */
        $product = Product::find($request->route('product_id'));
        if (is_null($product)) abort(404, 'Product not found');

        // Detach existing relationships
        /** @var Collection $existingAssignedCategories */
        $existingAssignedCategories = ProductCategory::whereIn('_id', $product->category_ids)->get();
        foreach ($existingAssignedCategories as $category) {
            $category->products()->detach([$product->id]);
        }

        // Attach new relationships
        /** @var Collection $newCategories */
        $newCategories = ProductCategory::whereIn('_id', (array) $request->ids)->get();
        foreach ($newCategories as $category) {
            $category->products()->attach([$product->id]);
        }

        return ['message' => 'Sync'];
    }

    public function getAllAuctionRegistrationRequests(Request $request): Collection
    {
        /** @var ?Store $store */
        $store = Store::find($request->route('store_id'));
        if (is_null($store)) abort(404, 'Store not found');

        return AuctionRegistrationRequest::where('store_id', $store->id)
            ->where('status', '!=', Status::DELETED->value)
            ->with([
                'requestedCustomer',
                'requestedCustomer.account',
                'deposits' => function ($q) {
                    $q->where('status', '!=', Status::DELETED->value);
                }
            ])
            ->latest()
            ->get();
    }

    public function getAllRegisteredUsers(Request $request): Collection
    {
        /** @var Collection $registeredCustomers */
        $registeredCustomers = AuctionRegistrationRequest::where('store_id', $request->route('store_id'))
            ->where('reply_status', $request->reply_status ?? ReplyStatus::APPROVED->value)
            ->whereNotNull('paddle_id')
            ->latest()
            ->get()
            ->keyBy('requested_by_customer_id');

        return Customer::whereIn('_id', $registeredCustomers->keys())
            ->with(['account.user'])
            ->get()
            ->each(function ($customer) use ($registeredCustomers) {
                $customer->paddle_id = $registeredCustomers[$customer->id]->paddle_id ?? '';
            });
    }

    public function removeRegisteredUser(Request $request): array
    {
        /** @var AuctionRegistrationRequest $registeredCustomerRequest*/
        $registeredCustomerRequest = AuctionRegistrationRequest::where('store_id', $request->route('store_id'))
            ->where('requested_by_customer_id', $request->route('customer_id'))
            ->latest()
            ->first();
        if (is_null($registeredCustomerRequest)) abort(404, 'Customer is not registered to this Auction');

        $registeredCustomerRequest->update(['reply_status' => ReplyStatus::REJECTED->value]);

        return ['message' => 'AuctionRegistrationRequest is now updated as REJECTED'];
    }

    public function addRegisteredUser(Request $request): array
    {
        /** @var ?Store $store */
        $store = Store::find($request->route('store_id'));
        if (is_null($store)) abort(404, 'Auction not found');

        /** @var ?AuctionRegistrationRequest $oldForm */
        $oldForm = AuctionRegistrationRequest::where('requested_by_customer_id', $request->route('customer_id'))
            ->where('store_id', $request->route('store_id'))
            ->first();
        if (!is_null($oldForm)) {
            $oldForm->update([
                'approved_by_account_id' => $this->account()->id,
                'status' => Status::ACTIVE->value,
                'paddle_id' => $request->paddle_id ?? $oldForm->paddle_id,
                'reply_status' => ReplyStatus::APPROVED->value,
            ]);

            return [
                'message' => 'Re-activated previously created AuctionRegistrationRequest successfully',
                'id' => $oldForm->id
            ];
        }

        if ($store->auction_type == 'ONLINE') {
            /** @var ?int $latestPaddleId */
            $latestPaddleId = AuctionRegistrationRequest::where('store_id', $request->route('store_id'))
                ->whereNotNull('paddle_id')
                ->orderByDesc('paddle_id')
                ->value('paddle_id');

            /** @var int $newPaddleId */
            $newPaddleId = is_null($latestPaddleId)
                ? ($store->paddle_number_start_from ?? 1)
                : $latestPaddleId + 1;

            /** @var AuctionRegistrationRequest $newForm */
            $newForm = AuctionRegistrationRequest::create([
                'requested_by_customer_id' => $request->route('customer_id'),
                'store_id' => $request->route('store_id'),
                'paddle_id' => $newPaddleId,
                'status' => Status::ACTIVE->value,
                'reply_status' => ReplyStatus::APPROVED->value
            ]);

            return [
                'message' => 'Created New AuctionRegistrationRequest successfully',
                'id' => $newForm->id
            ];
        } else if ($store->auction_type == 'LIVE') {
            /** @var AuctionRegistrationRequest $newForm */
            $newForm = AuctionRegistrationRequest::create([
                'requested_by_customer_id' => $request->route('customer_id'),
                'store_id' => $request->route('store_id'),
                'paddle_id' => $request->paddle_id,
                'status' => Status::ACTIVE->value,
                'reply_status' => ReplyStatus::APPROVED->value
            ]);

            return [
                'message' => 'Created New AuctionRegistrationRequest successfully',
                'id' => $newForm->id
            ];
        }

        return [
            'message' => 'Created New AuctionRegistrationRequest successfully',
            'id' => null
        ];
    }

    public function getAllAuctionRegistrationRecords(Request $request): Collection
    {
        return AuctionRegistrationRequest::where('store_id', $request->route('store_id'))
            ->with(['deposits'])
            ->get();
    }

    public function getAllCategories(Request $request): Collection
    {
        /** @var ?Store $store */
        $store = Store::find($request->route('store_id'));
        if (is_null($store)) abort(404, 'Auction not found');

        $statuses = (array) $request->input('status', Status::defaultStatuses());
        /** @var Collection $categories */
        $categories = $store->productCategories()
            ->statusesAllowed(Status::defaultStatuses(), $statuses)
            ->get();

        /** @var array $productIDs */
        $productIDs = AuctionLot::where('store_id', $request->route('store_id'))
            ->pluck('product_id')
            ->all();

        foreach ($categories as $category) {
            $category->lot_count = count(array_intersect($category->item_ids, $productIDs));
        }

        return $categories;
    }

    public function getAllAuctions(Request $request): Collection
    {
        $statuses = (array) $request->input('status', Status::defaultStatuses());
        /** @var Collection $stores */
        $stores = Store::where('auction_type', $request->input('auction_type'))
            ->statusesAllowed(Status::defaultStatuses(), $statuses)
            ->latest()
            ->get();

        foreach ($stores as $store) {
            $store->auction_lot_count = AuctionLot::where('store_id', $store->_id)
                ->statusesAllowed([Status::ACTIVE->value, Status::ARCHIVED->value])
                ->count();

            $store->registered_user_count = AuctionRegistrationRequest::where('store_id', $store->_id)
                ->where('reply_status', ReplyStatus::APPROVED->value)
                ->count();
        }

        return $stores;
    }

    public function updateAuctionStatuses(): array
    {
        $now = now();

        // Update Stores from ARCHIVED to ACTIVE when store's time is between start and end
        /** @var Collection $archivedStores */
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
                AuctionLot::where('store_id', $store->id)
                    ->where('status', Status::ARCHIVED->value)
                    ->update(['status' => Status::ACTIVE->value]);

                $archivedStoresUpdateCount++;
            }
        }

        // Update Stores from ACTIVE to ARCHIVED when store's time should be ended
        /** @var Collection $activeStores */
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
        /** @var ?Store $store */
        $store = Store::find($request->route('store_id'));
        if (is_null($store)) abort(404, 'Store not found');

        /** @var Collection $allAuctionLots */
        $allAuctionLots = AuctionLot::where('store_id', $request->route('store_id'))
            ->where('status', Status::ACTIVE->value)
            ->get();

        /** @var AuctionLot $lot */
        foreach ($allAuctionLots as $lot) {
            try {
                $currentBid = $lot->getCurrentBidPrice();

                // Reject auction lot
                if ($lot->is_bid_placed == false || $lot->reserve_price > $currentBid) {
                    // Update Product
                    Product::where('id', $lot->product_id)->update(['listing_status' => 'AVAILABLE']);

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
                        'auction_lot_id' => $lot->id,
                        'remarks' => 'Not met reserved price'
                    ]);
                } else {
                    // Get final highest bidder info
                    /** @var Collection $allBids */
                    $allBids = $lot->bids()->where('is_hidden', false)->get();
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

        return ['message' => 'Archived Store Successfully, updated ' . $allAuctionLots->count() . ' Auction Lots.'];
    }

    public function generateAuctionOrders(Request $request)
    {
        /** @var ?Store $store */
        $store = Store::find($request->route('store_id'));
        if (is_null($store)) abort(404, 'Store not found');

        // Get Auction Lots
        $unpaidAuctionLots = AuctionLot::where('store_id', $request->route('store_id'))
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
                        'store_id' => $request->route('store_id'),
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
                    ->each(function ($item) {
                        $item->is_checkout = true;
                        $item->is_refundable = false;
                        $item->global_discount = null;
                    });

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
                    'is_paid' => $request->boolean('is_paid', false),
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

                    $variantIDs[] = $attributes['product_variant_id'];
                    $order->createCartItem($attributes);
                }

                // Update Order
                $status = Str::slug(ShipmentDeliveryStatus::SUBMITTED->value);
                $order->updateStatus($status);

                // Create Checkout
                Checkout::create([
                    'order_id' => $order->_id,
                    'payment_method' => $paymentMethod
                ]);

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

        return ['message' => "Generated All {$generatedOrderCount} Auction Store Orders Successfully"];
    }

    public function getAllUnpaidAuctionLots(Request $request): Collection
    {
        return AuctionLot::where('store_id', $request->route('store_id'))
            ->whereNotNull('winning_bid_customer_id')
            ->where('is_paid', false)
            ->with(['product', 'store'])
            ->get();
    }

    public function returnAuctionLotToOriginalCustomer(Request $request): array
    {
        // Get Order
        $order = Order::find($request->input('id'));
        if (!$order) abort(404, 'Order not found');
        if ($order->payment_method != OrderPaymentMethod::OFFLINE->value) abort(404, 'This order ID ' . $order->id . ' is an Online Payment Order, items cannot be returned.');

        // Get variant IDs
        $storeID = $order->store_id;
        $variantIDs = collect($order->cart_items)->pluck('product_variant_id')->all();

        $unpaidAuctionLots = AuctionLot::where('store_id', $storeID)
            ->whereIn('product_variant_id', $variantIDs)
            ->where('is_paid', false)
            ->get();

        foreach ($unpaidAuctionLots as $lot) {
            $lot->update(["winning_bid_customer_id" => null]);
        }

        // Update Product status, and reset AuctionLot WinningCustomer
        $productIDs = $unpaidAuctionLots->pluck('product_id');
        Product::whereIn('_id', $productIDs)->update(['listing_status' => 'AVAILABLE']);

        return [
            'message' => 'Updated listing_status for ' . count($productIDs) . ' Product(s).'
        ];
    }

    public function closeAllNonDisabledLots(Request $request): array
    {
        AuctionLot::where('store_id', $request->route('store_id'))
            ->where('status', '!=', Status::DELETED->value)
            ->whereNotNull('lot_number')
            ->where('is_disabled', false)
            ->update([
                'status' => Status::ACTIVE->value,
                'is_disabled' => true,
                'is_closed' => true
            ]);

        return [
            'message' => 'Close all Lots successfully'
        ];
    }

    public function aggregate(Request $request)
    {
        $collection = $request->input('collection');
        $pipeline = $request->input('pipeline');

        return DB::connection('mongodb')
            ->collection($collection)
            ->raw(function ($collection) use ($pipeline) {
                return $collection->aggregate($pipeline);
            })
            ->toArray();
    }
}
