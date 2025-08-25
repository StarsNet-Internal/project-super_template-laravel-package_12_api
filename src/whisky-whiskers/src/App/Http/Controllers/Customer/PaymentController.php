<?php

namespace Starsnet\Project\WhiskyWhiskers\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// Enums
use App\Enums\CheckoutApprovalStatus;
use App\Enums\ShipmentDeliveryStatus;

// Models
use App\Models\Alias;
use App\Models\Checkout;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShoppingCartItem;
use Starsnet\Project\WhiskyWhiskers\App\Models\AuctionLot;
use Starsnet\Project\WhiskyWhiskers\App\Models\ProductStorageRecord;

class PaymentController extends Controller
{
    public function onlinePaymentCallback(Request $request)
    {
        $isPaid = $request->boolean('paid');
        $paymentMethod = $request->payment_method;

        $checkout = Checkout::where('online.transaction_id', $request->transaction_id)->first();
        Checkout::where('id', $checkout->id)->update(['online.api_response' => $request->all()]);

        $status = $isPaid ? CheckoutApprovalStatus::APPROVED->value : CheckoutApprovalStatus::REJECTED->value;
        $reason = $isPaid ? 'Payment verified by System' : 'Payment failed';
        $checkout->createApproval($status, $reason);

        /** @var ?Order $order */
        $order = $checkout->order;
        if (is_null($order)) abort(404, 'Order not found');
        /** @var ?Customer $customer */
        $customer = $order->customer;
        if (is_null($customer)) abort(404, 'Customer not found');

        if ($isPaid && $order->current_status !== ShipmentDeliveryStatus::PROCESSING->value) {
            $order->setTransactionMethod($paymentMethod);
            $order->updateStatus(ShipmentDeliveryStatus::PROCESSING->value);
        }

        // Delete ShoppingCartItem(s)
        /** @var ?Store $store */
        $store = $order->store;
        $variantIDs = collect($order->cart_items)->pluck('product_variant_id')->all();
        ShoppingCartItem::where('customer_id', $customer->id)
            ->where('store_id', $store->id)
            ->whereIn('product_variant_id', $variantIDs)
            ->delete();

        // Get Store
        $productIDs = collect($order->cart_items)->pluck('product_id')->all();
        $defaultMainStore = Alias::where('key', 'default-main-store')->latest()->first();

        if ($store->id == optional($defaultMainStore)->value) {
            if (count($productIDs) > 0) {
                Product::whereIn('_id', $productIDs)->update(
                    ['listing_status' => 'ALREADY_CHECKOUT']
                );
            }
        } else {
            // Auction Store logic
            if (count($productIDs) > 0) {
                // Update Product ownership and listing_status
                $listingStatus = $order->is_storage ? 'AVAILABLE' : 'ALREADY_CHECKOUT';
                Product::whereIn($productIDs)->update(
                    [
                        'owned_by_customer_id' => $order->customer_id,
                        'listing_status' => $listingStatus
                    ]
                );

                // Update AuctionLot paid status
                AuctionLot::whereIn('product_id', $productIDs)->update([
                    'winning_bid_customer_id' => $order->customer_id,
                    'is_paid' => true
                ]);

                foreach ($productIDs as $productID) {
                    $winningAuctionLot = AuctionLot::where('product_id', $productID)
                        ->where('store_id', $store->_id)
                        ->first();
                    if (!is_null($winningAuctionLot)) {
                        $winningBid = $winningAuctionLot->current_bid;
                        $nowString = now()->toIso8601String();
                        ProductStorageRecord::create([
                            'customer_id' => $order->customer_id,
                            'product_id' => $productID,
                            'start_datetime' => $nowString,
                            'winning_bid' => $winningBid
                        ]);

                        ProductStorageRecord::where('product_id', $productID)
                            ->whereNull('end_datetime')
                            ->update([
                                'end_datetime' => $nowString,
                            ]);
                    }
                }
            }

            // Attach relationship with previous system-generated order
            $previousGeneratedOrder = Order::where('customer_id', $order->customer_id)
                ->where('store_id', $order->store_id)
                ->where('_id', '!=', $order->_id)
                ->orderBy('created_at', 'asc')
                ->first();

            if (!is_null($previousGeneratedOrder)) {
                $previousGeneratedOrder->update(['paid_order_id' => $order->_id]);
            }
        }

        return 'SUCCESS';
    }
}
