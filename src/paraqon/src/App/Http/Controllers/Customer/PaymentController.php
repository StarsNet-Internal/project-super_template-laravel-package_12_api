<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Traits
use App\Http\Controllers\Traits\DistributePointTrait;

// Enums
use App\Enums\CheckoutApprovalStatus;
use App\Enums\CheckoutType;
use App\Enums\ShipmentDeliveryStatus;

// Models
use App\Models\Checkout;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ShoppingCartItem;
use App\Models\Store;

class PaymentController extends Controller
{
    use DistributePointTrait;

    public function onlinePaymentCallback(Request $request)
    {
        // Extract attributes from $request
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

        if (!$isPaid && $order->current_status !== ShipmentDeliveryStatus::CANCELLED->value) {
            $order->updateStatus(ShipmentDeliveryStatus::CANCELLED->value);
            return;
        }

        $requiredPoints = $order->getTotalPoint();
        if ($paymentMethod === CheckoutType::OFFLINE->value && $requiredPoints > 0) {
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

        $this->processMembershipPoints($customer, $order);

        /** @var Store $store */
        $store = $order->store;
        $variantIDs = collect($order->cart_items)->pluck('product_variant_id')->all();
        ShoppingCartItem::where('customer_id', $customer->id)
            ->where('store_id', $store->id)
            ->whereIn('product_variant_id', $variantIDs)
            ->delete();

        return response()->json('SUCCESS', 200);
    }
}
