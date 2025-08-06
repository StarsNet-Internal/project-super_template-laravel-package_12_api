<?php

namespace Starsnet\Project\Stripe\App\Http\Controllers\Customer;

// Laravel built-in
use App\Enums\CheckoutApprovalStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Traits
use App\Http\Controllers\Traits\DistributePointTrait;
use App\Http\Controllers\Traits\ShoppingCartTrait;

// Enums
use App\Enums\CheckoutType;
use App\Enums\ShipmentDeliveryStatus;
use App\Enums\Status;
use App\Enums\OrderDeliveryMethod;

// Models
use App\Models\Alias;
use App\Models\Checkout;
use App\Models\Configuration;
use App\Models\Customer;
use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\ShoppingCartItem;
use App\Models\Store;

class OrderController extends Controller
{
    use ShoppingCartTrait, DistributePointTrait;

    public function checkOut(Request $request)
    {
        $now = now();

        /** @var ?Store $store */
        $store = Store::find($request->route('store_id'))
            ?? Store::find(Alias::getValue($request->route('store_id')));
        if (is_null($store)) abort(404, 'Store not found');

        // Get authenticated User information
        $customer = $this->customer();

        // Remove ShoppingCartItem(s) if variant is not ACTIVE status
        $customer->shoppingCartItems()
            ->where('store_id', $store->id)
            ->whereHas('productVariant', fn($q) => $q->where('status', '!=', Status::ACTIVE->value))
            ->delete();

        // Extract attributes from $request
        $checkoutVariantIDs = $request->checkout_product_variant_ids;
        $voucherCode = $request->voucher_code;
        $deliveryInfo = $request->delivery_info;
        $deliveryDetails = $request->delivery_details;
        $paymentMethod = $request->payment_method;

        $courierID = $deliveryInfo['method'] === OrderDeliveryMethod::DELIVERY->value ?
            $deliveryInfo['courier_id'] :
            null;

        // Get ShoppingCartItem(s)
        $cartItems = $this->getCartItems($customer, $checkoutVariantIDs);
        $checkoutItems = $cartItems->filter(fn($item) => $item->is_checkout == true);

        $priceDetails = $this->calculatePriceDetails($checkoutItems);
        $validDiscounts = $this->getValidDiscounts(
            $store->id,
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
        $giftItems = $this->processGiftItems($discounts['buyXGetYFree']);

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
        if (!$isEnoughMembershipPoints) abort(403, 'Customer does not have enough membership points for this transaction');

        // Create Order
        $totalPrice = $calculation['price']['total'];
        if ($totalPrice <= 0) $paymentMethod = CheckoutType::OFFLINE->value;

        // Create Order
        $orderAttributes = [
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'is_paid' => $request->input('is_paid', false),
            'payment_method' => $paymentMethod,
            'cart_items' => $checkoutItems,
            'gift_items' => $giftItems,
            'discounts' => $this->formatDiscounts($discounts),
            'calculations' => $calculation,
            'delivery_info' => $deliveryInfo,
            'delivery_details' => $deliveryDetails,
            'is_voucher_applied' => !is_null($discounts['voucher']),
        ];
        /** @var Order $order */
        $order = Order::create($orderAttributes);

        // Update Order
        $status = Str::slug(ShipmentDeliveryStatus::SUBMITTED->value);
        $order->updateStatus($status);

        // Create Checkout
        $checkout = Checkout::create([
            'order_id' => $order->id,
            'payment_method' => $paymentMethod,
        ]);

        $orderID = $order->id;
        if ($order->getTotalPrice() > 0) {
            switch ($paymentMethod) {
                case CheckoutType::ONLINE->value:
                    try {
                        /** @var Order $order */
                        $order = $checkout->order;
                        $amount = $order->calculations['price']['total'];

                        $config = Configuration::where('slug', 'stripe-payment-credentials')->latest()->first();

                        $url = 'https://api.stripe.com/v1/payment_intents';
                        $response = Http::asForm()
                            ->withToken($config->secret_key)
                            ->post($url, [
                                'amount' => $amount * 100,
                                'currency' => 'HKD'
                            ]);

                        $transactionID = $response['id'];
                        $clientSecret = $response['client_secret'];

                        Checkout::where('id', $checkout->id)->update(['online.transaction_id' => $transactionID]);
                    } catch (\Throwable $th) {
                        return 'Transaction failed';
                    }
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
        } else {
            // Distribute MembershipPoint
            $this->processMembershipPoints($customer, $order);
        }

        // Update MembershipPoint, for Offline Payment via MINI Store
        $totalPoints = $calculation['point']['total'];
        if ($paymentMethod === CheckoutType::OFFLINE->value && $totalPoints > 0) {
            $history = $customer->deductMembershipPoints($totalPoints);

            $historyAttributes = [
                'description' => [
                    'en' => 'Thank you for your redemption.',
                    'zh' => '兌換成功！謝謝！',
                    'cn' => '兑换成功！谢谢！'
                ],
                'remarks' => ''
            ];
            $history->update($historyAttributes);
        }

        // Delete ShoppingCartItem(s)
        if ($paymentMethod === CheckoutType::OFFLINE->value) {
            ShoppingCartItem::where('customer_id', $customer->id)
                ->where('store_id', $store->id)
                ->whereIn('product_variant_id', $checkoutVariantIDs)
                ->delete();
        }

        // Use voucherCode
        if (!is_null($voucherCode)) {
            /** @var ?DiscountCode $voucher */
            $voucher = DiscountCode::where('full_code', $voucherCode)
                ->where('is_used', false)
                ->where('is_disabled', false)
                ->first();
            if ($voucher) $voucher->usedByOrder($order);
        }

        // Return data
        if ($paymentMethod === CheckoutType::ONLINE->value) {
            return [
                'message' => 'Created a transaction from Stripe successfully',
                'order_id' => $orderID,
                'transaction_id' => $transactionID,
                'client_secret' => $clientSecret
            ];
        } else {
            return [
                'message' => 'Created new order',
                'order_id' => $orderID,
                'transaction_id' => null,
                'client_secret' => null
            ];
        }
    }

    public function onlinePaymentCallback(Request $request)
    {
        // Extract attributes from $request
        $transactionID = $request->data['object']['id'];
        $isPaid = $request->type == 'payment_intent.succeeded';
        if ($isPaid === false) abort(200, "Invalid type: {$request->type}, expected value: payment_intent.succeeded");

        /** @var ?Checkout $checkout */
        $checkout = Checkout::where('online.transaction_id', $transactionID)->first();
        if (is_null($checkout)) abort(200, "Checkout not found");

        // Update Checkout and Order
        Checkout::where('id', $checkout->id)->update(['online.api_response' => $request->all()]);
        $status = $isPaid
            ? CheckoutApprovalStatus::APPROVED->value
            : CheckoutApprovalStatus::REJECTED->value;
        $reason = $isPaid
            ? 'Payment verified by System'
            : 'Payment failed';
        $checkout->createApproval($status, $reason);

        // Get Order and Customer
        /** @var ?Order $order */
        $order = $checkout->order;
        if (is_null($checkout)) abort(200, "Order not found");
        /** @var ?Customer $customer */
        $customer = $order->customer;
        if (is_null($customer)) abort(200, "Customer not found");

        // Update Order status
        if ($isPaid == true && $order->current_status !== ShipmentDeliveryStatus::PROCESSING->value) {
            $order->update(['transaction_method' => 'CREDIT CARD']);
            $order->updateStatus(ShipmentDeliveryStatus::PROCESSING->value);
        }

        // Deduct MembershipPoint(s)
        $totalPoints = $order->calculations['point']['total'];
        if ($totalPoints > 0) {
            $history = $customer->deductMembershipPoints($totalPoints);
            $history->update(['remarks' => 'Redemption Record for Order ID: ' . $order->_id]);
        }

        // Delete ShoppingCartItem(s)
        /** @var ?Store $store */
        $store = $order->store;
        if (is_null($customer)) abort(200, "Updated Order and MembershipPoint, but cart cannot be cleared.");

        $variantIDs = collect($order->cart_items)->pluck('product_variant_id')->all();
        ShoppingCartItem::where('customer_id', $customer->id)
            ->where('store_id', $store->id)
            ->whereIn('product_variant_id', $variantIDs)
            ->delete();

        // Distribute MembershipPoint(s)
        $this->processMembershipPoints($customer, $order);

        return [
            "Updated Order and MembershipPoint, anc cleared cart for customer_id: {$customer->id}."
        ];
    }
}
