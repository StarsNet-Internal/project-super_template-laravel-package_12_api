<?php

namespace Starsnet\Project\Rmhc\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;

// Enums
use App\Enums\CheckoutApprovalStatus;
use App\Enums\CheckoutType;
use App\Enums\ShipmentDeliveryStatus;
use App\Enums\Status;
use App\Models\Checkout;
use App\Models\Customer;
use App\Models\Order;

// Models
use App\Models\ShoppingCartItem;
use App\Models\Store;
use Starsnet\Project\Paraqon\App\Models\AuctionLot;
use Starsnet\Project\Rmhc\App\Models\BatchPayment;
use Starsnet\Project\Rmhc\App\Models\WinningCustomerHistory;

class ServiceController extends Controller
{
    public function paymentCallback(Request $request): array
    {
        $acceptableEventTypes = [
            'charge.succeeded',
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

        // $winningHistories = WinningCustomerHistory::whereIn('auction_lot_id', $winningLotIDs)
        //     ->get()
        //     ->keyBy('auction_lot_id');

        // Create ShoppingCartItem(s) from each AuctionLot
        foreach ($resultLots as $resultLot) {
            $winningLot = $winningLots[$resultLot['lot_id']] ?? null;
            // $winningHistory = $winningHistories[$resultLot->lot_id] ?? null;
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
}
