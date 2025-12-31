<?php

namespace Starsnet\Project\Auction\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Enums
use App\Enums\CheckoutApprovalStatus;
use App\Enums\CheckoutType;
use App\Enums\ReplyStatus;
use App\Enums\ShipmentDeliveryStatus;
use App\Enums\Status;
use App\Models\Checkout;
use App\Models\Customer;
use App\Models\Order;

// Models
use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Facades\Log;
use Starsnet\Project\Paraqon\App\Models\AuctionLot;
use Starsnet\Project\Paraqon\App\Models\AuctionRegistrationRequest;
use Starsnet\Project\Paraqon\App\Models\Deposit;

class ServiceController extends Controller
{
    public function paymentCallback(Request $request): array
    {
        $acceptableEventTypes = [
            'charge.succeeded',
            'charge.refunded',
            'charge.captured',
            'charge.expired',
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

        // ===============
        // Handle Events
        // ===============
        switch ($request->type) {
            case 'setup_intent.succeeded': {
                    // ---------------------
                    // If bind card success
                    // ---------------------
                    if ($model == 'customer') {
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
                    // ---------------------
                    // When Stripe DB created a new customer
                    // ---------------------
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
                    if ($model === 'deposit') {
                        /** @var ?Deposit $deposit */
                        $deposit = Deposit::find($modelID);
                        if (is_null($deposit)) abort(404, 'Deposit not found');

                        /** @var ?AuctionRegistrationRequest $auctionRegistrationRequest */
                        $auctionRegistrationRequest = $deposit->auctionRegistrationRequest;
                        if (is_null($auctionRegistrationRequest)) abort(404, 'AuctionRegistrationRequest not found');

                        // Update Deposit
                        $deposit->updateStatus('on-hold');
                        Deposit::where('id', $deposit->id)
                            ->update([
                                'reply_status' => ReplyStatus::APPROVED->value,
                                'online.api_response' => $request->all()
                            ]);

                        if ($auctionRegistrationRequest->reply_status === ReplyStatus::APPROVED->value) {
                            return ['message' => 'Deposit approved'];
                        }

                        // Update AuctionRegistrationRequest
                        $assignedPaddleId = $auctionRegistrationRequest->paddle_id;

                        if (is_null($assignedPaddleId)) {
                            $allPaddles = AuctionRegistrationRequest::where('store_id', $auctionRegistrationRequest->store_id)
                                ->pluck('paddle_id')
                                ->filter(fn($id) => is_numeric($id))
                                ->map(fn($id) => (int) $id)
                                ->sort()
                                ->values();
                            $latestPaddleId = $allPaddles->last();

                            $assignedPaddleId = is_null($latestPaddleId) ?
                                $store->paddle_number_start_from ?? 1 :
                                $latestPaddleId + 1;
                        }

                        $requestUpdateAttributes = [
                            'paddle_id' => $assignedPaddleId,
                            'status' => Status::ACTIVE->value,
                            'reply_status' => ReplyStatus::APPROVED->value
                        ];
                        $auctionRegistrationRequest->update($requestUpdateAttributes);

                        return [
                            'message' => 'Deposit status updated as on-hold',
                            'deposit_id' => $deposit->_id
                        ];
                    }

                    if ($model === 'checkout') {
                        /** @var ?Checkout $checkout */
                        $checkout = Checkout::find($modelID);
                        if (is_null($checkout)) abort(404, 'Checkout not found');

                        /** @var ?Order $order */
                        $order = $checkout->order;
                        if (is_null($order)) abort(404, 'Order not found');

                        // Update Checkout
                        Checkout::where('id', $checkout->id)
                            ->update([
                                'approval.status' => CheckoutApprovalStatus::APPROVED->value,
                                'approval.reason' => 'Payment verified by Stripe',
                                'online.api_response' => $request->all()
                            ]);

                        $customEventType = $request->data['object']['metadata']['custom_event_type'] ?? null;
                        $storeID = $order->store_id;
                        $store = Store::find($storeID);

                        if ($customEventType === null || $customEventType === 'full_capture') {
                            $order->update(['is_paid' => true]);

                            // Update Order
                            if ($order->current_status !== ShipmentDeliveryStatus::PROCESSING->value) {
                                $order->updateStatus(ShipmentDeliveryStatus::PROCESSING->value);
                            }

                            // Update Product and AuctionLot
                            if (!is_null($store) && in_array($store->auction_type, ['LIVE', 'ONLINE'])) {
                                $productIDs = collect($order->cart_items)->pluck('product_id')->all();

                                AuctionLot::where('store_id', $storeID)
                                    ->whereIn('product_id', $productIDs)
                                    ->update(['is_paid' => true]);

                                Product::objectIDs($productIDs)->update([
                                    'owned_by_customer_id' => $order->customer_id,
                                    'status' => 'ACTIVE',
                                    'listing_status' => 'ALREADY_CHECKOUT'
                                ]);
                            }
                        }

                        if ($customEventType === 'full_capture' || $customEventType === 'partial_capture') {
                            $updateData = [];

                            if (isset($request->data['object']['metadata']['deposit_amount'])) {
                                $updateData['calculations.deposit'] = $request->data['object']['metadata']['deposit_amount'];
                            }

                            if (isset($request->data['object']['metadata']['new_total'])) {
                                $updateData['calculations.price.total'] = $request->data['object']['metadata']['new_total'];
                            }

                            if (!empty($updateData)) {
                                Order::where('_id', $order->id)->update($updateData);

                                $originalOrderID = $request->data['object']['metadata']['original_order_id'] ?? null;
                                $originalOrder = Order::find($originalOrderID);
                                if (!is_null($originalOrder)) {
                                    Order::where('_id', $originalOrderID)->update($updateData);
                                }
                            }
                        }

                        if (in_array($store->auction_type, ['LIVE', 'ONLINE'])) {
                            $productIDs = collect($order->cart_items)->pluck('product_id')->all();

                            AuctionLot::where('store_id', $order->store_id)
                                ->whereIn('product_id', $productIDs)
                                ->update(['is_paid' => true]);

                            Product::whereIn('_id', $productIDs)->update([
                                'owned_by_customer_id' => $order->customer_id,
                                'status' => 'ACTIVE',
                                'listing_status' => 'ALREADY_CHECKOUT'
                            ]);
                        }

                        return [
                            'message' => 'Checkout approved, and Order status updated',
                            'order_id' => $order->id
                        ];
                    }
                }
            case 'charge.refunded': {
                    if ($model === 'deposit') {
                        /** @var ?Deposit $deposit */
                        $deposit = Deposit::find($modelID);
                        if (is_null($deposit)) abort(404, 'Deposit not found');

                        // Update Deposit
                        $amountCaptured = $request->data['object']['amount_captured'] ?? 0;
                        $amountRefunded = $request->data['object']['amount_refunded'] ?? 0;
                        $deposit->update([
                            'amount_captured' => $amountCaptured / 100,
                            'amount_refunded' => $amountRefunded / 100,
                        ]);
                        $deposit->updateStatus('returned');

                        return [
                            'message' => 'Deposit status updated as returned',
                            'deposit_id' => $deposit->_id
                        ];
                    }
                }
            case 'charge.captured': {
                    if ($model === 'deposit') {
                        /** @var ?Deposit $deposit */
                        $deposit = Deposit::find($modelID);
                        if (is_null($deposit)) abort(404, 'Deposit not found');

                        // Update Deposit
                        $amountCaptured = $request->data['object']['amount_captured'] ?? 0;
                        $amountRefunded = $request->data['object']['amount_refunded'] ?? 0;
                        $deposit->update([
                            'amount_captured' => $amountCaptured / 100,
                            'amount_refunded' => $amountRefunded / 100,
                        ]);

                        $deposit->updateStatus('returned');

                        return [
                            'message' => 'Deposit status updated as returned',
                            'deposit_id' => $deposit->_id
                        ];
                    }
                }
            case 'charge.expired': {
                    if ($model === 'deposit') {
                        /** @var ?Deposit $deposit */
                        $deposit = Deposit::find($modelID);
                        if (is_null($deposit)) abort(404, 'Deposit not found');

                        // Update Deposit
                        $amountCaptured = $request->data['object']['amount_captured'] ?? 0;
                        $amountRefunded = $request->data['object']['amount_refunded'] ?? 0;
                        $deposit->update([
                            'amount_captured' => $amountCaptured / 100,
                            'amount_refunded' => $amountRefunded / 100,
                        ]);

                        $deposit->updateStatus('returned');

                        return [
                            'message' => 'Deposit status updated as returned',
                            'deposit_id' => $deposit->_id
                        ];
                    }
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

    public function createAuctionOrder(Request $request)
    {
        $now = now();

        /** @var ?Order $originalOrder */
        $originalOrder = Order::find($request->order_id);
        if (is_null($originalOrder)) abort(404, 'Order not found');

        /** @var ?Customer $customer */
        $customer = Customer::find($originalOrder->customer_id);
        if (is_null($customer)) abort(404, 'Customer not found');

        /** @var ?Store $store */
        $store = Store::find($originalOrder->store_id);
        if (is_null($store)) abort(404, 'Store not found');

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

        // Create Order
        $newOrderAttributes = [
            'customer_id' => $originalOrder->customer_id,
            'store_id' => $originalOrder->store_id,
            'payment_method' => CheckoutType::ONLINE->value,
            'cart_items' => $originalOrder['cart_items']->toArray(),
            'gift_items' => $originalOrder['gift_items']->toArray(),
            'discounts' => $originalOrder['discounts'],
            'calculations' => $originalOrder['calculations'],
            'delivery_info' => $originalOrder['delivery_info'],
            'delivery_details' => $originalOrder['delivery_details'],
            'is_paid' => false,
            'is_voucher_applied' => false,
            'is_system' => false,
            'payment_information' => [
                'currency' => 'HKD',
                'conversion_rate' => 1
            ],
        ];
        /** @var Order $newOrder */
        $newOrder = Order::create($newOrderAttributes);

        // Update Order
        $status = Str::slug(ShipmentDeliveryStatus::SUBMITTED->value);
        $newOrder->updateStatus($status);

        // Create Checkout
        /** @var Checkout $checkout */
        $checkout = $newOrder->checkout()->create([
            'payment_method' => CheckoutType::ONLINE->value
        ]);

        // Validate charge
        $totalPrice = $originalOrder['calculations']['price']['total'];

        // Update for starting from 2025/09 Auction, partial capture
        $chargeCalculator = function ($amount): int {
            if ($amount <= 1000) return $amount; // For 0 - 1000
            return $amount <= 10000
                ? $amount % 1000 + (intval($amount / 1000)) * 1000 / 5 // remainder + thousands
                : $amount % 1000 + (intval($amount / 1000)) * 1000 / 4; // remainder + thousands
        };

        $chargeAmount = $chargeCalculator((float) $totalPrice);
        $stripeAmount = (int) $chargeAmount * 100;
        $newTotalPrice = max(0, floor($totalPrice - $chargeAmount));
        $customEventType = $newTotalPrice === 0
            ? 'full_capture'
            : 'partial_capture';

        if ($stripeAmount < 400) abort(400, "Given stripe amount is $stripeAmount (\$$totalPrice), which is lower than the min of 400 ($4.00)");

        // Create and force payment via Stripe
        try {
            $stripeData = [
                "amount" => $stripeAmount,
                "currency" => 'hkd',
                "customer_id" => $customer->stripe_customer_id,
                "payment_method_id" => $customer->stripe_payment_method_id,
                "metadata" => [
                    "model_type" => 'checkout',
                    "model_id" => $checkout->id,
                    'custom_event_type' => $customEventType,
                    'original_order_id' => $originalOrder->id,
                    'deposit_amount' => number_format($chargeAmount, 2, '.', ''),
                    'new_total' => number_format($newTotalPrice, 2, '.', '')
                ]
            ];

            $url = env('TCG_BID_STRIPE_BASE_URL', 'http://192.168.0.83:8083') . '/bind-card/charge';
            $response = Http::post($url, $stripeData);

            if ($response->failed()) {
                $error = $response->json()['error'] ?? 'Stripe API request failed';
                throw new \Exception(json_encode($error));
            }

            // Update Checkout
            $checkout->update([
                'amount' => $totalPrice,
                'currency' => 'hkd',
                'online' => [
                    'payment_intent_id' => $response['id'],
                    'client_secret' => $response['clientSecret'],
                    'api_response' => null
                ],
            ]);

            return [
                'message' => 'Submitted Order successfully',
                'checkout' => $checkout,
                'order_id' => $newOrder->id
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
