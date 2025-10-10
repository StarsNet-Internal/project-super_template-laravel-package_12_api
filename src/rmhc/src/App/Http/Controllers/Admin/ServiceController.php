<?php

namespace Starsnet\Project\Rmhc\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;

// Enums
use App\Enums\CheckoutApprovalStatus;
use App\Enums\CheckoutType;
use App\Enums\OrderPaymentMethod;
use App\Enums\ShipmentDeliveryStatus;
use App\Enums\Status;
use App\Models\Account;
use App\Models\Alias;
use App\Models\Checkout;
use App\Models\Customer;
use App\Models\Order;

// Models
use App\Models\ShoppingCartItem;
use App\Models\Store;
use App\Models\Warehouse;
use Starsnet\Project\Paraqon\App\Models\AuctionLot;
use Starsnet\Project\Rmhc\App\Models\BatchPayment;

class ServiceController extends Controller
{
    public function paymentCallback(Request $request): array
    {
        $acceptableEventTypes = [
            'charge.succeeded',
            'setup_intent.succeeded',
            'payment_method.attached'
        ];

        if (!in_array($request->type, $acceptableEventTypes)) {
            return [
                'message' => 'Callback success, but event type does not belong to any of the acceptable values',
                'acceptable_values' => $acceptableEventTypes
            ];
        }

        // Extract metadata from $request
        $model = $request->data['object']['metadata']['model_type'] ?? null;
        $modelID = $request->data['object']['metadata']['model_id'] ?? null;
        $customEventType = $request->data['object']['metadata']['custom_event_type'] ?? null;

        // ===============
        // Handle Events
        // ===============
        switch ($request->type) {
            case 'setup_intent.succeeded': {
                    if ($customEventType === 'bind_credit_card') {
                        /** @var ?Customer $customer */
                        $customer = Customer::find($modelID);
                        if (is_null($customer)) abort(404, 'Customer not found');

                        $customer->update(['stripe_payment_method_id' => $request->data['object']['payment_method']]);

                        return [
                            'message' => 'Customer updated',
                            'customer_id' => $customer->id,
                        ];
                    }
                    break;
                }
            case 'payment_method.attached': {
                    /** @var ?Customer $customer */
                    $customer = Customer::where('stripe_payment_method_id', $request->data['object']['id'])
                        ->latest()
                        ->first();
                    if (is_null($customer)) abort(404, 'Customer not found');

                    $customer->update([
                        'stripe_customer_id' => $request->data['object']['customer'],
                        'stripe_card_binded_at' => now(),
                        'stripe_card_data' => $request->data['object']['card']
                    ]);

                    return [
                        'message' => 'Customer updated',
                        'customer_id' => $customer->_id,
                    ];
                }
            case 'charge.succeeded': {
                    if ($model === 'batch_payment') {
                        // Update BatchPayment
                        /** @var ?BatchPayment $batchPayment */
                        $batchPayment = BatchPayment::find($modelID);
                        if (is_null($batchPayment)) abort(404, 'BatchPayment not found');

                        $batchPayment->update([
                            'is_approved' => true,
                            'api_response' => $request->all(),
                            'payment_received_at' => now()
                        ]);

                        // Update Checkout
                        $orderIDs = $batchPayment->order_ids;

                        Checkout::whereIn('order_id', $orderIDs)
                            ->update([
                                'online.api_response' => $request->all()
                            ]);

                        $checkouts = Checkout::whereIn('order_id', $orderIDs)->get();
                        foreach ($checkouts as $checkout) {
                            $checkout->createApproval(CheckoutApprovalStatus::APPROVED->value,  'Payment verified by Stripe');
                        }

                        // Update Orders
                        /** @var ?Order $order */
                        Order::whereIn('_id', $orderIDs)->update(['is_paid' => true]);

                        $orders = Order::whereIn('_id', $orderIDs)->get();
                        foreach ($orders as $order) {
                            $order->updateStatus(ShipmentDeliveryStatus::PROCESSING->value);
                        }

                        return [
                            'message' => 'Checkout approved, and Order status updated',
                            'order_id' => $order->id
                        ];
                    }
                    break;
                }
            default: {
                    abort(400, "Invalid eventType given: $request->type");
                    return [
                        'message' => "Invalid eventType given: $request->type",
                        'acceptable_event_types' => $acceptableEventTypes
                    ];
                }
        }

        return [
            'message' => 'An unknown error occuried',
            'received_request_body' => $request->all()
        ];
    }

    public function generateAuctionOrders(Request $request): array
    {
        /** @var ?Store $store */
        $store = Store::find($request->route('store_id'));
        if (is_null($store)) abort(404, 'Store not found');
        if ($store->status === Status::ACTIVE->value) abort(200, "Store is still ACTIVE. Skipping generating auction order sequences.");
        // if ($store->status === Status::DELETED->value) abort(200, "Store is already DELETED. Skipping generating auction order sequences.");

        // Generate OFFLINE order by system
        $generatedOrderCount = 0;

        foreach ($request->results as $result) {
            try {
                /** @var ?Customer $customer */
                $customer = Customer::find($result['customer_id']);
                if (is_null($customer)) continue;

                // Create Order
                $this->createAuctionOrder($store, $customer, collect($result['lots']));
                $generatedOrderCount++;
            } catch (\Throwable $th) {
                print($th);
            }
        }

        return ['message' => "Generated {$generatedOrderCount} Auction Store Orders Successfully"];
    }

    private function createAuctionOrder(
        Store $store,
        Customer $customer,
        Collection $resultLots
    ): Order {
        $winningLotIDs = $resultLots->pluck('lot_id')->all();
        $winningLots = AuctionLot::whereIn('_id', $winningLotIDs)
            ->get()
            ->keyBy('_id');

        // Create ShoppingCartItem(s) from each AuctionLot
        foreach ($resultLots as $resultLot) {
            $winningLot = $winningLots[$resultLot['lot_id']] ?? null;
            $price = $resultLot['price'];

            $attributes = [
                'store_id' => $store->id,
                'customer_id' => $customer->id,
                'product_id' => $winningLot->product_id,
                'product_variant_id' => $winningLot->product_variant_id,
                'qty' => 1,
                'lot_number' => $winningLot->lot_number,
                'winning_bid' => $price,
                'sold_price' => $price,
                'commission' => 0
            ];
            ShoppingCartItem::create($attributes);
        }

        // Initialize calculation variables
        $itemTotalPrice = 0;

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
            ->each(function ($item) use (&$itemTotalPrice) {
                $item->is_refundable = false;
                $item->global_discount = null;
                $itemTotalPrice += $item->sold_price;
            });

        // Form calculation data object
        $rawCalculation = [
            'currency' => 'HKD',
            'price' => [
                'subtotal' => number_format($itemTotalPrice, 2, '.', ''),
                'total' => number_format($itemTotalPrice, 2, '.', '') // Deduct price_discount.local and .global
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
            'deposit' => '0.00',
            'storage_fee' => '0.00',
            'shipping_fee' => '0.00'
        ];

        // Create Order
        $orderAttributes = [
            'store_id' => $store->id,
            'customer_id' => $customer->id,
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
            'is_paid' => false,
            'is_voucher_applied' => false,
            'is_system' => true,
            'payment_information' => [
                'currency' => 'HKD',
                'conversion_rate' => 1.00
            ]
        ];
        $order = Order::create($orderAttributes);

        // Create OrderCartItem(s)
        $variantIDs = [];
        foreach ($cartItems as $item) {
            $attributes = $item->toArray();
            unset($attributes['_id']);

            /** @var ProductVariant $variant */
            $order->createCartItem($attributes);
        }

        // Create Checkout
        $attributes = ['payment_method' => CheckoutType::OFFLINE->value];
        $order->checkout()->create($attributes);

        // Update Order
        $status = Str::slug(ShipmentDeliveryStatus::SUBMITTED->value);
        $order->updateStatus($status);

        // Delete ShoppingCartItem(s)
        $variantIDs = $cartItems->pluck('product_variant_id')->values()->all();
        ShoppingCartItem::where('customer_id', $customer->id)
            ->where('store_id', $store->id)
            ->whereIn('product_variant_id', $variantIDs)
            ->delete();

        return $order;
    }

    public function createBatchPaymentAndCharge(Request $request)
    {
        // Extract attributes from $request
        $customerID = $request->customer_id;

        // Set default values
        $now = now();
        $systemAuctionStoreOrder = null;
        $systemDonationStoreOrders = collect();

        // Find Stores
        /** @var ?Store $auctionStore */
        $auctionStore = Store::find($request->auction_store_id ?? 'default-auction-store')
            ?? Store::find(Alias::getValue($request->auction_store_id ?? 'default-auction-store'));
        /** @var ?Store $donationStore */
        $donationStore = Store::find($request->donation_store_id ?? 'default-donation-store')
            ?? Store::find(Alias::getValue($request->donation_store_id ?? 'default-donation-store'));

        // Find Order(s)
        if ($auctionStore instanceof Store) {
            /** @var ?Order $systemAuctionStoreOrder */
            $systemAuctionStoreOrder = Order::where('store_id', $auctionStore->_id)
                ->where('customer_id', $customerID)
                ->where('is_system', true)
                ->latest()
                ->first();
        }

        if ($donationStore instanceof Store) {
            /** @var ?Collection $systemDonationStoreOrders */
            $systemDonationStoreOrders = Order::where('store_id', $donationStore->_id)
                ->where('customer_id', $customerID)
                ->where('is_system', true)
                ->get();
        }

        // Validations
        if (is_null($systemAuctionStoreOrder) && $systemDonationStoreOrders->count() === 0) {
            return [
                'message' => 'No order found for this customer',
                'provided_customer_id' => $customerID,
                'provided_auction_store_id' => $request->auction_store_id ?? 'default-auction-store',
                'found_auction_store_id' => optional($auctionStore)->_id,
                'provided_donation_store_id' => $request->donation_store_id ?? 'default-donation-store',
                'found_donation_store_id' => optional($donationStore)->_id,
            ];
        }

        // Find Customer and Account
        /** @var ?Customer $customer */
        $customer = Customer::find($customerID);
        if (is_null($customer)) abort(404, 'Customer not found');
        /** @var ?Account $account */
        $account = Account::find($customer->account_id);
        if (is_null($account)) abort(404, 'Account not found');

        // Validate Stripe payment info
        if (
            is_null($customer->stripe_customer_id) ||
            is_null($customer->stripe_payment_method_id) ||
            is_null($customer->stripe_card_data)
        ) {
            abort(404, 'Customer stripe payment info not found');
        }

        // Validate card
        $currentYear = (int) $now->format('Y');
        $currentMonth = (int) $now->format('m');
        $expYear = (int) $customer->stripe_card_data['exp_year'];
        $expMonth = (int) $customer->stripe_card_data['exp_month'];

        if (!($expYear > $currentYear ||
            ($expYear === $currentYear && $expMonth >= $currentMonth)
        )) {
            abort(400, 'Customer stripe payment info expired');
        }

        // Clone Orders
        $allSystemOrders = collect();
        if ($systemAuctionStoreOrder instanceof Order) $allSystemOrders->push($systemAuctionStoreOrder);
        if ($systemDonationStoreOrders instanceof Collection) $allSystemOrders = $allSystemOrders->merge($systemDonationStoreOrders);

        // Get DeliveryDetails
        $defaultWarehouse = Warehouse::where('slug', 'default-warehouse')
            ->latest()
            ->first();
        $deliveryInfo = [
            'country_code' => 'HK',
            'method' => 'SELF_PICKUP',
            'courier_id' => 'HK',
            'warehouse_id' => $defaultWarehouse->_id,
        ];
        $deliveryDetails = [
            'recipient_name' => [
                'first_name' => $account->first_name,
                'last_name' => $account->last_name,
            ],
            'email' => $account->email,
            'area_code' => $account->area_code,
            'phone' => $account->phone,
            'address' => $customer->delivery_recipient['address']
        ];

        $clonedOrderIDs = [];
        foreach ($allSystemOrders as $order) {
            $duplicatedOrder = $order
                ->replicate()
                ->fill([
                    'is_system' => false,
                    'delivery_info' => $deliveryInfo,
                    'delivery_details' => $deliveryDetails,
                    'is_paid' => false,
                    'system_order_id' => $order->_id,
                    'payment_method' => OrderPaymentMethod::ONLINE->value
                ]);
            $duplicatedOrder->save();

            // Create Checkout
            Checkout::create([
                'order_id' => $duplicatedOrder->id,
                'payment_method' => OrderPaymentMethod::ONLINE->value
            ]);

            $clonedOrderIDs[] = $duplicatedOrder->id;
        }

        // Calculate Total Amount
        $totalAmount = (int) $allSystemOrders->sum(function ($order) {
            return floatval($order->calculations['price']['total'] ?? 0);
        });

        // Create BatchPayment
        $batchPayment = BatchPayment::create([
            'customer_id' => $customer->_id,
            'order_ids' => $clonedOrderIDs,
            'total_amount' => number_format($totalAmount, 2, '.', ''),
            'currency' => 'HKD',
            'payment_method' => OrderPaymentMethod::ONLINE->value
        ]);

        // Charge Directly
        $stripeAmount = (int) $totalAmount * 100;
        if ($stripeAmount < 400) abort(400, "Given stripe amount is $stripeAmount (\$$totalAmount), which is lower than the min of 400 ($4.00)");

        try {
            $stripeData = [
                'amount' => $stripeAmount,
                'currency' => 'HKD',
                'customer_id' => $customer->stripe_customer_id,
                'payment_method_id' => $customer->stripe_payment_method_id,
                'metadata' => [
                    'model_type' => 'batch_payment',
                    'model_id' => $batchPayment->id
                ]
            ];

            $url = env('RMHC_STRIPE_BASE_URL', 'http://192.168.0.83:8083') . '/bind-card/charge';
            $response = Http::post($url, $stripeData);
            Log::info($response);
            if ($response->failed()) {
                $error = $response->json()['error'] ?? 'Stripe API request failed';
                throw new \Exception(json_encode($error));
            }

            // Update Checkout
            $batchPayment->update([
                'payment_intent_id' => $response['id'],
                'client_secret' => $response['clientSecret'],
                'api_response' => null
            ]);

            return [
                'message' => 'Created transaction successfully',
                'batch_payment' => $batchPayment,
                'new_order_ids' => $clonedOrderIDs
            ];
        } catch (\Exception $e) {
            abort(404, 'Payment processing failed');
            return [
                'message' => 'Payment processing failed',
                'url' => $url,
                'method' => 'POST',
                'data' => $stripeData,
                'error' => json_decode($e->getMessage(), true) ?: $e->getMessage()
            ];
        }
    }
}
