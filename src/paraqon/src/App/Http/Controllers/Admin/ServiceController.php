<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

// Enums
use App\Enums\CheckoutApprovalStatus;
use App\Enums\CheckoutType;
use App\Enums\OrderPaymentMethod;
use App\Enums\ReplyStatus;
use App\Enums\ShipmentDeliveryStatus;
use App\Enums\Status;

// Models
use App\Models\Checkout;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShoppingCartItem;
use App\Models\Store;
use Illuminate\Http\Client\Response;
use Starsnet\Project\Paraqon\App\Models\AuctionLot;
use Starsnet\Project\Paraqon\App\Models\AuctionRegistrationRequest;
use Starsnet\Project\Paraqon\App\Models\Deposit;
use Starsnet\Project\Paraqon\App\Models\LiveBiddingEvent;

// Controllers
use App\Http\Controllers\Customer\ProductManagementController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Admin\AuctionLotController as AdminAuctionLotController;
use Starsnet\Project\Paraqon\App\Http\Controllers\Customer\AuctionLotController as CustomerAuctionLotController;

class ServiceController extends Controller
{
    public function paymentCallback(Request $request): array
    {
        // Extract attributes from $request
        $eventType = (string) $request->type;

        // Validation
        $acceptableEventTypes = [
            'charge.succeeded',
            'charge.refunded',
            'charge.captured',
            'charge.expired',
            'charge.failed'
        ];
        if (!in_array($eventType, $acceptableEventTypes)) {
            return [
                'message' => 'Callback success, but event type does not belong to any of the acceptable values',
                'acceptable_values' => $acceptableEventTypes
            ];
        }

        // Extract attributes from $request
        $model = $request->data['object']['metadata']['model_type'] ?? null;
        $modelID = $request->data['object']['metadata']['model_id'] ?? null;
        if (is_null($model) || is_null($modelID)) abort(400, 'Callback success, but metadata contains null value for either model_type or model_id');

        // Find Model and Update
        switch ($model) {
            case 'deposit':
                /** @var ?Deposit $deposit */
                $deposit = Deposit::find($modelID);
                if (is_null($deposit)) abort(404, 'Deposit not found');

                /** @var ?AuctionRegistrationRequest $auctionRegistrationRequest */
                $auctionRegistrationRequest = $deposit->auctionRegistrationRequest;

                // Update Deposit
                if ($eventType == 'charge.succeeded') {
                    $deposit->updateStatus('on-hold');
                    Deposit::where('id', $deposit->id)->update([
                        'reply_status' => ReplyStatus::APPROVED->value,
                        'online.api_response' => $request->all()
                    ]);

                    // Automatically assign paddle_id if ONLINE auction
                    if (in_array($auctionRegistrationRequest->reply_status, [
                        ReplyStatus::PENDING->value,
                        ReplyStatus::REJECTED->value
                    ])) {
                        // get Paddle ID
                        $assignedPaddleID = $auctionRegistrationRequest->paddle_id;
                        $storeID = $auctionRegistrationRequest->store_id;

                        $newPaddleID = $assignedPaddleID;
                        if (is_null($newPaddleID)) {
                            $allPaddles = AuctionRegistrationRequest::where('store_id', $storeID)
                                ->pluck('paddle_id')
                                ->filter(fn($id) => is_numeric($id))
                                ->map(fn($id) => (int) $id)
                                ->sort()
                                ->values();
                            $latestPaddleId = $allPaddles->last();

                            if (is_null($latestPaddleId)) {
                                $store = Store::find($storeID);
                                $newPaddleID = $store->paddle_number_start_from ?? 1;
                            } else {
                                $newPaddleID = $latestPaddleId + 1;
                            }
                        }

                        $requestUpdateAttributes = [
                            'paddle_id' => $newPaddleID,
                            'status' => Status::ACTIVE->value,
                            'reply_status' => ReplyStatus::APPROVED->value
                        ];
                        $auctionRegistrationRequest->update($requestUpdateAttributes);
                    }

                    return [
                        'message' => 'Deposit status updated as on-hold',
                        'deposit_id' => $deposit->_id
                    ];
                } else if (in_array($eventType, ['charge.refunded', 'payment_intent.canceled'])) {
                    $deposit->updateStatus('returned');

                    $amountCaptured = $request->data['object']['amount_captured'] ?? 0;
                    $amountRefunded = $request->data['object']['amount_refunded'] ?? 0;

                    $deposit->update([
                        'amount_captured' => $amountCaptured / 100,
                        'amount_refunded' => $amountRefunded / 100,
                    ]);

                    return [
                        'message' => 'Deposit status updated as returned',
                        'deposit_id' => $deposit->_id
                    ];
                } else if ($eventType == 'charge.captured') {
                    $deposit->updateStatus('returned');

                    $amountCaptured = $request->data['object']['amount_captured'] ?? 0;
                    $amountRefunded = $request->data['object']['amount_refunded'] ?? 0;

                    $deposit->update([
                        'amount_captured' => $amountCaptured / 100,
                        'amount_refunded' => $amountRefunded / 100,
                    ]);

                    return [
                        'message' => 'Deposit status updated as returned',
                        'deposit_id' => $deposit->_id
                    ];
                } else if ($eventType == 'charge.expired') {
                    $deposit->updateStatus('returned');

                    $amountCaptured = $request->data['object']['amount_captured'] ?? 0;
                    $amountRefunded = $request->data['object']['amount_refunded'] ?? 0;

                    $deposit->update([
                        'amount_captured' => $amountCaptured / 100,
                        'amount_refunded' => $amountRefunded / 100,
                    ]);

                    return [
                        'message' => 'Deposit status updated as returned',
                        'deposit_id' => $deposit->_id
                    ];
                } else if ($eventType == 'charge.failed') {
                    $deposit->updateStatus('cancelled');

                    $deposit->update([
                        'reply_status' => ReplyStatus::REJECTED->value,
                        'stripe_api_reponse' => $request->data['object']
                    ]);

                    return [
                        'message' => 'Deposit status updated as cancelled',
                        'deposit_id' => $deposit->_id
                    ];
                }

                return [
                    'message' => 'Invalid Stripe event type',
                    'deposit_id' => null
                ];
            case 'checkout':
                /** @var ?Checkout $checkout */
                $checkout = Checkout::find($modelID);
                if (is_null($checkout)) abort(200, 'Checkout not found');

                /** @var ?Order $order */
                $order = $checkout->order;
                if (is_null($order)) abort(200, 'Order not found');

                // Update Checkout and Order
                if ($eventType == 'charge.succeeded') {
                    // Update Checkout
                    Checkout::where('id', $checkout->id)->update([
                        'online.api_response' => $request->all()
                    ]);
                    $checkout->createApproval(
                        CheckoutApprovalStatus::APPROVED->value,
                        'Payment verified by Stripe'
                    );

                    // Update Order
                    if ($order->current_status !== ShipmentDeliveryStatus::PROCESSING->value) {
                        $order->updateStatus(ShipmentDeliveryStatus::PROCESSING->value);
                    }

                    // Update Product and AuctionLot
                    $store = Store::find($order->store_id);
                    if (
                        !is_null($store) &&
                        in_array($store->auction_type, ['LIVE', 'ONLINE'])
                    ) {
                        $productIDs = collect($order->cart_items)->pluck('product_id')->all();

                        AuctionLot::where('store_id', $order->store_id)
                            ->whereIn('product_id', $productIDs)
                            ->update(['is_paid' => true]);

                        Product::objectIDs($productIDs)->update([
                            'owned_by_customer_id' => $order->customer_id,
                            'status' => Status::ACTIVE->value,
                            'listing_status' => 'ALREADY_CHECKOUT'
                        ]);
                    }

                    return [
                        'message' => 'Checkout approved, and Order status updated',
                        'order_id' => $order->_id
                    ];
                }

                abort(404, 'Invalid Stripe event type');
                break;
            default:
                abort(400, 'Invalid model_type for metadata');
                break;
        }
        return ['message' => 'Payment was not processed properly due to invalie event_type'];
    }

    public function updateAuctionStatuses(): array
    {
        $now = now();

        // ----------------------
        // Auction (Store) Starts
        // ----------------------

        // Make stores ACTIVE
        $archivedStores = Store::where('auction_type', 'ONLINE')
            ->where('status', Status::ARCHIVED->value)
            ->get();

        $archivedStoresUpdateCount = 0;
        foreach ($archivedStores as $store) {
            $startTime = Carbon::parse($store->start_datetime)->startOfMinute();
            $endTime = Carbon::parse($store->end_datetime)->startOfMinute();

            if ($now >= $startTime && $now <= $endTime) {
                $store->update(['status' => Status::ACTIVE->value]);
                $archivedStoresUpdateCount++;
            }
        }

        // Make stores ARCHIVED
        $activeStores = Store::where('auction_type', 'ONLINE')
            ->where('status', Status::ACTIVE->value)
            ->get();

        $activeStoresUpdateCount = 0;
        foreach ($activeStores as $store) {
            $endTime = Carbon::parse($store->end_datetime)->startOfMinute();

            if ($now >= $endTime) {
                $store->update(['status' => Status::ARCHIVED->value]);
                $activeStoresUpdateCount++;
            }
        }

        // ----------------------
        // Auction (Store) Ends
        // ----------------------

        // ----------------------
        // Auction Lot Starts
        // ----------------------

        // Make lots ACTIVE
        $archivedLots = AuctionLot::where('status', Status::ARCHIVED->value)
            ->whereHas('store', function ($query) {
                return $query->where('status', Status::ACTIVE->value)
                    ->where('auction_type', 'ONLINE');
            })
            ->get();

        $archivedLotsUpdateCount = 0;
        foreach ($archivedLots as $lot) {
            $startTime = Carbon::parse($lot->start_datetime)->startOfMinute();
            $endTime = Carbon::parse($lot->end_datetime)->startOfMinute();

            if ($now >= $startTime && $now < $endTime) {
                $lot->update(['status' => Status::ACTIVE->value]);
                $archivedLotsUpdateCount++;
            }
        }

        // Make lots ARCHIVED
        $activeLots = AuctionLot::where('status', Status::ACTIVE->value)
            ->whereHas('store', function ($query) {
                return $query->where('auction_type', 'ONLINE');
            })->get();

        $activeLotsUpdateCount = 0;
        foreach ($activeLots as $lot) {
            $endTime = Carbon::parse($lot->end_datetime)->startOfMinute();

            if ($now >= $endTime) {
                $lot->update(['status' => Status::ARCHIVED->value]);
                $activeLotsUpdateCount++;
            }
        }

        // ----------------------
        // Auction Lot Ends
        // ----------------------

        return [
            'now_time' => $now,
            'activated_store_count' => $archivedStoresUpdateCount,
            'archived_store_count' => $activeStoresUpdateCount,
            'activated_lot_count' => $archivedLotsUpdateCount,
            'archived_lot_count' => $activeLotsUpdateCount,
            'message' => "Updated Successfully"
        ];
    }

    public function updateAuctionLotStatuses(): array
    {
        $now = now();

        // Make lots ACTIVE
        $archivedLots = AuctionLot::where('status', Status::ARCHIVED->value)
            ->whereHas('store', function ($query) {
                return $query->where('status', Status::ACTIVE->value);
            })
            ->get();

        $archivedLotsUpdateCount = 0;
        foreach ($archivedLots as $lot) {
            $startTime = Carbon::parse($lot->start_datetime);
            $endTime = Carbon::parse($lot->end_datetime);

            if ($now >= $startTime && $now < $endTime) {
                $lot->update(['status' => Status::ACTIVE->value]);
                $archivedLotsUpdateCount++;
            }
        }

        // Make lots ARCHIVED
        $activeLots = AuctionLot::where('status', Status::ACTIVE->value)->get();

        $activeLotsUpdateCount = 0;
        foreach ($activeLots as $lot) {
            $endTime = Carbon::parse($lot->end_datetime);

            if ($now >= $endTime) {
                $lot->update(['status' => Status::ARCHIVED->value]);
                $activeLotsUpdateCount++;
            }
        }

        return [
            'now_time' => $now,
            'message' => "Updated {$archivedLotsUpdateCount} AuctionLot(s) as ACTIVE, and {$activeLotsUpdateCount} AuctionLot(s) as ARCHIVED"
        ];
    }

    private function cancelDeposit(Deposit $deposit): bool
    {
        switch ($deposit->payment_method) {
            case OrderPaymentMethod::ONLINE->value:
                $paymentIntentID = $deposit->online['payment_intent_id'];
                $url = env('PARAQON_STRIPE_BASE_URL', 'https://payment.paraqon.starsnet.hk') . '/payment-intents/' . $paymentIntentID . '/cancel';

                try {
                    $response = Http::post($url);
                    return $response->status() === 200;
                } catch (\Throwable $th) {
                    Log::error('Failed to cancel deposit, deposit_id: ' . $deposit->_id);
                    $deposit->updateStatus('return-failed');
                    return false;
                }
            case OrderPaymentMethod::OFFLINE->value:
                $deposit->update([
                    'amount_captured' => 0,
                    'amount_refunded' => $deposit->amount,
                ]);
                return true;
            default:
                return false;
        }
    }

    private function captureDeposit(Deposit $deposit, $captureAmount)
    {
        switch ($deposit->payment_method) {
            case OrderPaymentMethod::ONLINE->value:
                try {
                    $paymentIntentID = $deposit->online['payment_intent_id'];
                    $url = env('PARAQON_STRIPE_BASE_URL', 'https://payment.paraqon.starsnet.hk') . '/payment-intents/' . $paymentIntentID . '/capture';

                    $data = ['amount' => $captureAmount * 100];
                    $response = Http::post($url, $data);
                    return $response->status() === 200;
                } catch (\Throwable $th) {
                    Log::error('Failed to capture deposit, deposit_id: ' . $deposit->_id);
                    $deposit->updateStatus('return-failed');
                    return false;
                }
                return false;
            case OrderPaymentMethod::OFFLINE->value:
                $deposit->update([
                    'amount_captured' => $captureAmount,
                    'amount_refunded' => $deposit->amount - $captureAmount,
                ]);
                return true;
            default:
                return false;
        }
    }

    private function createAuctionOrder(
        Store $store,
        Customer $customer,
        Collection $winningLots,
        Collection $deposits
    ): Order {
        // Create ShoppingCartItem(s) from each AuctionLot
        foreach ($winningLots as $lot) {
            $attributes = [
                'store_id' => $store->_id,
                'product_id' => $lot->product_id,
                'product_variant_id' => $lot->product_variant_id,
                'qty' => 1,
                'lot_number' => $lot->lot_number,
                'winning_bid' => $lot->current_bid,
                'sold_price' => $lot->sold_price ?? $lot->current_bid,
                'commission' => $lot->commission ?? 0
            ];
            $customer->shoppingCartItems()->create($attributes);
        }

        // Get ShoppingCartItem(s), then do calculations before creating Order
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

        // Initialize calculation variables
        $itemTotalPrice = 0;

        // Update items and calculate total price
        $cartItems->each(function ($item) use (&$itemTotalPrice) {
            $item->is_checkout = true;
            $item->is_refundable = false;
            $item->global_discount = null;
            $itemTotalPrice += $item->sold_price;
        });

        // Get total price
        $orderTotalPrice = $itemTotalPrice;

        // Calculate totalCapturedDeposit
        $totalCustomerOnHoldDepositAmount = $deposits->sum('amount');
        $totalCapturableDeposit = min($totalCustomerOnHoldDepositAmount, $orderTotalPrice);

        // Start capturing deposits, and refund excess
        $depositToBeDeducted = $totalCapturableDeposit;
        foreach ($deposits as $deposit) {
            if ($depositToBeDeducted <= 0) {
                $this->cancelDeposit($deposit);
            } else {
                $currentDepositAmount = $deposit->amount;
                $captureDeposit = min($currentDepositAmount, $depositToBeDeducted);
                $isCapturedSuccessfully = $this->captureDeposit($deposit, $captureDeposit);
                if ($isCapturedSuccessfully == true) $depositToBeDeducted -= $captureDeposit;
            }
        }

        // Update total price
        $orderTotalPrice -= $totalCapturableDeposit;

        // Form calculation data object
        $rawCalculation = [
            'currency' => 'HKD',
            'price' => [
                'subtotal' => number_format($itemTotalPrice, 2, '.', ''),
                'total' => number_format($orderTotalPrice, 2, '.', '') // Deduct price_discount.local and .global
            ],
            'price_discount' => [
                'local' => 0,
                'global' => 0,
            ],
            'point' => [
                'subtotal' => 0,
                'total' => 0,
            ],
            'service_charge' => 0,
            'deposit' => number_format($totalCapturableDeposit, 2, '.', ''),
            'storage_fee' => 0,
            'shipping_fee' => 0
        ];

        // Create Order
        $orderAttributes = [
            'is_paid' => false,
            'payment_method' => CheckoutType::OFFLINE->value,
            'discounts' => [],
            'calculations' => $rawCalculation,
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
            'is_voucher_applied' => false,
            'is_system' => true,
            'payment_information' => [
                'currency' => 'HKD',
                'conversion_rate' => 1.00
            ]
        ];
        $order = $customer->createOrder($orderAttributes, $store);

        // Create OrderCartItem(s)
        $variantIDs = [];
        foreach ($cartItems as $item) {
            $attributes = $item->toArray();
            unset($attributes['_id'], $attributes['is_checkout']);

            /** @var ProductVariant $variant */
            $order->createCartItem($attributes);
        }

        // Update Order
        $status = Str::slug(ShipmentDeliveryStatus::SUBMITTED->value);
        $order->updateStatus($status);

        // Create Checkout
        $attributes = ['payment_method' => CheckoutType::OFFLINE->value];
        $order->checkout()->create($attributes);

        // Delete ShoppingCartItem(s)
        $variantIDs = $cartItems->pluck('product_variant_id')->toArray();
        ShoppingCartItem::where('customer_id', $customer->id)
            ->where('store_id', $store->id)
            ->whereIn('product_variant_id', $variantIDs)
            ->delete();

        return $order;
    }

    public function generateAuctionOrdersAndRefundDeposits(Request $request): array
    {
        /** @var ?Store $store */
        $store = Store::find($request->route('store_id'));
        if (is_null($store)) abort(404, 'Store not found');
        if ($store->status === Status::ACTIVE->value) abort(200, "Store is still ACTIVE. Skipping generating auction order sequences.");
        if ($store->status === Status::DELETED->value) abort(200, "Store is already DELETED. Skipping generating auction order sequences.");

        // Get all winning customer ids
        $winningCustomerIDs = collect($request->results)->pluck('customer_id')->filter()->unique()->values()->toArray();

        // Get all Deposit(s), with on-hold current_deposit_status, from non-winning Customer(s)
        $storeID = $store->id;
        $allFullRefundDeposits = Deposit::whereHas(
            'auctionRegistrationRequest',
            function ($query) use ($storeID) {
                $query->whereHas('store', function ($query2) use ($storeID) {
                    $query2->where('_id', $storeID);
                });
            }
        )
            ->whereNotIn('requested_by_customer_id', $winningCustomerIDs)
            ->where('current_deposit_status', 'on-hold')
            ->get();

        // Full-refund all Deposit(s) from all non-winning Customer(s)
        foreach ($allFullRefundDeposits as $deposit) {
            $this->cancelDeposit($deposit);
        }

        // Generate OFFLINE order by system
        $generatedOrderCount = 0;

        // Update auction lots with inputted price
        foreach ($request->results as $result) {
            foreach ($result['lots'] as $lot) {
                AuctionLot::where('_id', $lot['lot_id'])
                    ->update([
                        'winning_bid_customer_id' => $result['customer_id'],
                        'current_bid' => $lot['price'],
                        'sold_price' => $lot['sold_price'],
                        'commission' => $lot['commission'],
                    ]);
            }
        }

        foreach ($request->results as $result) {
            try {
                // Extract attributes from $result
                $customerID = $result['customer_id'];
                $confirmedLots = collect($result['lots']);

                /** @var ?Customer $customer */
                $customer = Customer::find($customerID);
                if (is_null($customer)) continue;

                // Find all winning Auction Lots
                $winningLotIDs = $confirmedLots->pluck('lot_id')->all();
                $winningLots = AuctionLot::find($winningLotIDs);

                // Get all Deposit(s), with on-hold current_deposit_status, from this Customer
                $customerOnHoldDeposits = Deposit::whereHas('auctionRegistrationRequest', function ($query) use ($storeID) {
                    $query->whereHas('store', function ($query2) use ($storeID) {
                        $query2->where('_id', $storeID);
                    });
                })
                    ->where('requested_by_customer_id', $customer->_id)
                    ->where('current_deposit_status', 'on-hold')
                    ->get();

                // Create Order, and capture/refund deposits
                $this->createAuctionOrder($store, $customer, $winningLots, $customerOnHoldDeposits);

                $generatedOrderCount++;
            } catch (\Throwable $th) {
                print($th);
            }
        }

        return ['message' => "Generated {$generatedOrderCount} Auction Store Orders Successfully"];
    }

    public function generateLiveAuctionOrdersAndRefundDeposits(Request $request): array
    {
        /** @var ?Store $store */
        $store = Store::find($request->route('store_id'));
        if (is_null($store)) abort(404, 'Store not found');
        if ($store->status === Status::ACTIVE->value) abort(200, "Store is still ACTIVE. Skipping generating auction order sequences.");
        if ($store->status === Status::DELETED->value) abort(200, "Store is already DELETED. Skipping generating auction order sequences.");

        // Get all winning customer ids
        $winningCustomerIDs = collect($request->results)->pluck('customer_id')->filter()->unique()->values()->toArray();

        // Get all Deposit(s), with on-hold current_deposit_status, from non-winning Customer(s)
        $storeID = $store->id;
        $allFullRefundDeposits = Deposit::whereHas(
            'auctionRegistrationRequest',
            function ($query) use ($storeID) {
                $query->whereHas('store', function ($query2) use ($storeID) {
                    $query2->where('_id', $storeID);
                });
            }
        )
            ->whereNotIn('requested_by_customer_id', $winningCustomerIDs)
            ->where('current_deposit_status', 'on-hold')
            ->get();

        // Full-refund all Deposit(s) from all non-winning Customer(s)
        foreach ($allFullRefundDeposits as $deposit) {
            $this->cancelDeposit($deposit);
        }

        // Generate OFFLINE order by system
        $generatedOrderCount = 0;

        // Update auction lots with inputted price
        foreach ($request->results as $result) {
            foreach ($result['lots'] as $lot) {
                AuctionLot::where('_id', $lot['lot_id'])
                    ->update([
                        'winning_bid_customer_id' => $result['customer_id'],
                        'current_bid' => $lot['price'],
                        'sold_price' => $lot['sold_price'],
                        'commission' => $lot['commission'],
                    ]);
            }
        }

        // foreach ($winningCustomerIDs as $customerID) {
        foreach ($request->results as $result) {
            try {
                // Extract attributes from $result
                $customerID = $result['customer_id'];
                $confirmedLots = collect($result['lots']);

                /** @var ?Customer $customer */
                $customer = Customer::find($customerID);
                if (is_null($customer)) continue;

                // Find all winning Auction Lots
                $winningLotIDs = $confirmedLots->pluck('lot_id')->all();
                $winningLots = AuctionLot::find($winningLotIDs);

                // Get all Deposit(s), with on-hold current_deposit_status, from this Customer
                $customerOnHoldDeposits = Deposit::whereHas('auctionRegistrationRequest', function ($query) use ($storeID) {
                    $query->whereHas('store', function ($query2) use ($storeID) {
                        $query2->where('_id', $storeID);
                    });
                })
                    ->where('requested_by_customer_id', $customer->_id)
                    ->where('current_deposit_status', 'on-hold')
                    ->get();

                // Create Order, and capture/refund deposits
                $this->createAuctionOrder($store, $customer, $winningLots, $customerOnHoldDeposits);

                $generatedOrderCount++;
            } catch (\Throwable $th) {
                print($th);
            }
        }

        return ['message' => "Generated {$generatedOrderCount} Auction Store Orders Successfully"];
    }

    public function returnDeposit(Request $request): array
    {
        /** @var ?Deposit $deposit */
        $deposit = Deposit::where('online.payment_intent_id', $request->data['object']['id'])->first();
        if (is_null($deposit)) abort(404, 'Deposit not found');

        $deposit->updateStatus('returned');

        return [
            'message' => 'Updated Deposit as returned Successfully',
            'deposit_id' => $deposit->id
        ];
    }

    public function confirmOrderPaid(Request $request): array
    {
        /** @var ?Checkout $checkout */
        $checkout = Checkout::where('online.payment_intent_id',  $request->data['object']['id'])->first();
        if (is_null($checkout)) abort(404, 'Checkout not found');

        /** @var ?Order $order */
        $order = $checkout->order;
        if (is_null($order)) abort(404, 'Order not found');

        Checkout::where('id', $checkout->id)->update(['online.api_response' => $request->all()]);

        // Update Checkout and Order
        $isPaid = $request->boolean('is_paid', true);
        $status = $isPaid ?
            CheckoutApprovalStatus::APPROVED->value :
            CheckoutApprovalStatus::REJECTED->value;
        $reason = $isPaid ?
            'Payment verified by System' :
            'Payment failed';
        $checkout->createApproval($status, $reason);

        // Update Order status
        if ($isPaid && $order->current_status !== ShipmentDeliveryStatus::PROCESSING->value) {
            $order->updateStatus(ShipmentDeliveryStatus::PROCESSING->value);
        } else if (!$isPaid && $order->current_status !== ShipmentDeliveryStatus::CANCELLED->value) {
            $order->updateStatus(ShipmentDeliveryStatus::CANCELLED->value);
        }

        return [
            'message' => 'Updated Order as paid Successfully',
            'order_id' => $order->id
        ];
    }

    public function getAuctionCurrentState(Request $request): ?Response
    {
        // Get all AuctionLots
        $request->merge(['store_id' => $request->route('store_id')]);

        $adminAuctionLotController = new AdminAuctionLotController();
        $lots = $adminAuctionLotController->getAllAuctionLots($request);

        $currentLot = $this->getCurrentLot($lots);
        $currentLotId = $currentLot->_id;

        $highestAdvancedBid = $currentLot->bids()
            ->where('is_hidden',  false)
            ->where('type', 'ADVANCED')
            ->orderBy('bid', 'desc')
            ->first();

        $request->route()->setParameter('auction_lot_id', $currentLotId);
        $customerAuctionLotController = new CustomerAuctionLotController();
        $histories = $customerAuctionLotController->getBiddingHistory($request);

        $events = LiveBiddingEvent::where('store_id', $request->route('store_id'))
            ->where('value_1', $currentLotId)
            ->get();

        $data = [
            'lots' => $lots,
            'current_lot_id' => $currentLotId,
            'highest_advanced_bid' => $highestAdvancedBid,
            'histories' => $histories,
            'events' => $events,
            'time' => now(),
        ];

        try {
            $url = env('PARAQON_SOCKET_BASE_URL', 'https://socket.paraqon.starsnet.hk') . '/api/publish';
            $response = Http::post(
                $url,
                [
                    'site' => 'paraqon',
                    'room' => 'live-' . $request->route('store_id'),
                    'data' => $data,
                    'event' => 'liveBidding',
                ]
            );
            return $response;
        } catch (\Throwable $th) {
            return null;
        }
    }

    public function getCurrentLot(Collection $lots): ?AuctionLot
    {
        // Find PREPARING lot
        $preparingLot = $lots->first(function ($lot) {
            return $lot->status === Status::ARCHIVED->value && !$lot->is_disabled && $lot->is_closed;
        });
        if ($preparingLot) return $preparingLot;

        // Find OPEN lot
        $openedLot = $lots->first(function ($lot) {
            return $lot->status === Status::ACTIVE->value && !$lot->is_disabled && !$lot->is_closed;
        });
        if ($openedLot) return $openedLot;

        $sortedLots = $lots->sortBy('lot_number')->values();

        // Return last lot_number if all SOLD / CLOSE
        $isAllLotsDisabled = $lots->every(fn($lot) => $lot->is_disabled);
        if ($isAllLotsDisabled) return $sortedLots->last();

        // Return first lot_number if all UPCOMING
        $isAllLotsUpcoming = $lots->every(function ($lot) {
            return $lot->status == Status::ARCHIVED->value && !$lot->is_disabled && !$lot->is_closed;
        });
        if ($isAllLotsUpcoming) return $sortedLots->first();

        // Find last updated SOLD or CLOSE lot
        return $lots->filter(function ($lot) {
            return $lot->status === Status::ACTIVE->value;
        })
            ->sortByDesc('updated_at')
            ->first();
    }

    public function captureOrderPayment(Request $request): array
    {
        $orders = Order::where('scheduled_payment_at', '<=', now())
            ->whereNull('scheduled_payment_received_at')
            ->get();

        $orderIDs = [];
        foreach ($orders as $order) {
            try {
                $checkout = $order->checkout()->latest()->first();
                $paymentIntentID = $checkout->online['payment_intent_id'];
                $url = env('PARAQON_STRIPE_BASE_URL', 'https://payment.paraqon.starsnet.hk') . '/payment-intents/' . $paymentIntentID . '/capture';
                $response = Http::post($url, ['amount' => null]);
                if ($response->status() == 200) {
                    $orderIDs[] = $order->_id;
                    $order->update(['scheduled_payment_received_at' => now()]);
                }
            } catch (\Throwable $th) {
                Log::error('Failed to capture order, order_id: ' . $order->_id);
            }
        }

        return [
            'message' => 'Approved order count: ' . count($orderIDs),
            'order_ids' => $orderIDs
        ];
    }

    public function synchronizeAllProductsWithAlgolia(Request $request)
    {
        $controller = new ProductManagementController($request);
        $data = $controller->filterProductsByCategories($request);

        $url = env('PARAQON_ALGOLIA_NODE_BASE_URL') . '/algolia/mass-update';

        $payload = ['data' => $data];
        if ($request->has('index_name') && !is_null($request->index_name)) {
            $payload['index_name'] = $request->index_name;
        }

        return Http::post($url, $payload);
    }
}
