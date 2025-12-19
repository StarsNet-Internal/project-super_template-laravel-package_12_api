<?php

namespace Starsnet\Project\ShoppingCart\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// Traits
use App\Http\Controllers\Traits\DistributePointTrait;
use App\Http\Controllers\Traits\ShoppingCartTrait;

// Services
use App\Http\Starsnet\PinkiePay;

// Enums
use App\Enums\CheckoutType;
use App\Enums\ShipmentDeliveryStatus;
use App\Enums\OrderDeliveryMethod;
use App\Enums\Status;

// Models
use App\Models\Alias;
use App\Models\Checkout;
use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\ShoppingCartItem;
use App\Models\Store;

class CheckoutController extends Controller
{
    use ShoppingCartTrait, DistributePointTrait;

    /** @var Store $store */
    protected $store;

    public function __construct(Request $request)
    {
        $this->store = Store::find($request->route('store_id'))
            ?? Store::find(Alias::getValue($request->route('store_id')));
    }

    private function updateAsOnlineCheckout(Checkout $checkout, string $successUrl, string $cancelUrl): string
    {
        /** @var Order $order */
        $order = $checkout->order;

        // Instantiate PinkiePay
        $pinkiePay = new PinkiePay($order, $successUrl, $cancelUrl);

        // Create Payment token
        $amount = $order->amount_received;
        $data = $pinkiePay->createPaymentToken($amount);

        // Update Checkout
        $transactionID = $data['transaction_id'];
        Checkout::where('id', $checkout->id)->update([
            'online.transaction_id' => $transactionID
        ]);

        return $data['shortened_url'];
    }

    public function checkOut(Request $request): array
    {
        $now = now();

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
        $deliveryDetails = $request->delivery_details;
        $paymentMethod = $request->payment_method;
        $successUrl = $request->success_url;
        $cancelUrl = $request->cancel_url;

        $courierID = $deliveryInfo['method'] === OrderDeliveryMethod::DELIVERY->value
            ? $deliveryInfo['courier_id']
            : null;

        // Get ShoppingCartItem(s)
        $cartItems = $this->getCartItems($customer, $checkoutVariantIDs);
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
        if (!$isEnoughMembershipPoints) abort(403, 'Customer does not have enough membership points for this transaction');

        // Create Order
        $totalPrice = $calculation['price']['total'];
        if ($totalPrice <= 0) $paymentMethod = CheckoutType::OFFLINE->value;

        // Service Fee
        $serviceFee = $request->input('fixed_fee', 0) + $totalPrice * $request->input('variable_fee', 0);
        $totalPricePlusServiceFee =  $totalPrice + $serviceFee;

        // Create Order
        $orderAttributes = [
            'customer_id' => $customer->id,
            'store_id' => $this->store->id,
            'is_paid' => $request->input('is_paid', false),
            'payment_method' => $paymentMethod,
            'cart_items' => $checkoutItems,
            'gift_items' => $giftItems,
            'discounts' => $this->formatDiscounts($discounts),
            'calculations' => $calculation,
            'delivery_info' => $deliveryInfo,
            'delivery_details' => $deliveryDetails,
            'is_voucher_applied' => !is_null($discounts['voucher']),
            'change' => number_format($serviceFee, 2, '.', ''),
            'amount_received' => number_format($totalPricePlusServiceFee, 2, '.', '')
        ];
        /** @var Order $order */
        $order = Order::create($orderAttributes);

        foreach ($checkoutItems->values()->all() as $cartItem) {
            $cartItem = $cartItem->toArray();
            $order->orderCartItems()->create($cartItem);
        }
        foreach ($giftItems as $cartItem) {
            $order->orderGiftItems()->create($cartItem);
        }

        // Update Order
        $status = Str::slug(ShipmentDeliveryStatus::SUBMITTED->value);
        $order->updateStatus($status);

        // Create Checkout
        $checkout = Checkout::create([
            'order_id' => $order->id,
            'payment_method' => $paymentMethod,
        ]);

        $returnUrl = null;
        if ($order->getTotalPrice() > 0) {
            switch ($paymentMethod) {
                case CheckoutType::ONLINE->value:
                    $pinkiePay = new PinkiePay($order, $successUrl, $cancelUrl);
                    $data = $pinkiePay->createPaymentToken();
                    $returnUrl = $data['shortened_url'];
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

        // Delete ShoppingCartItem(s)
        if ($paymentMethod === CheckoutType::OFFLINE->value) {
            ShoppingCartItem::where('customer_id', $customer->id)
                ->where('store_id', $this->store->id)
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

        return [
            'message' => 'Submitted Order successfully',
            'return_url' => $returnUrl ?? null,
            'order_id' => $order->_id
        ];
    }
}
