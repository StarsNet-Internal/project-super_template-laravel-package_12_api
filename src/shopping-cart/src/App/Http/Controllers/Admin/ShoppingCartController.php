<?php

namespace Starsnet\Project\ShoppingCart\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// Traits
use App\Http\Controllers\Traits\ShoppingCartTrait;

// Enums
use App\Enums\OrderDeliveryMethod;
use App\Enums\Status;

// Models
use App\Models\Alias;
use App\Models\Customer;
use App\Models\Store;

class ShoppingCartController extends Controller
{
    use ShoppingCartTrait;

    /** @var Store $store */
    protected $store;

    public function __construct(Request $request)
    {
        $this->store = Store::find($request->route('store_id'))
            ?? Store::find(Alias::getValue($request->route('store_id')));
    }

    public function getAll(Request $request): array
    {
        $now = now();

        // Get authenticated User information
        $customerID = $request->customer_id;
        $customer = Customer::find($customerID);
        if (is_null($customer)) abort(404, 'Customer not found');

        // Remove ShoppingCartItem(s) if variant is not ACTIVE status
        $customer->shoppingCartItems()
            ->where('store_id', $this->store->id)
            ->whereHas('productVariant', fn($q) => $q->where('status', '!=', Status::ACTIVE->value))
            ->delete();

        // Extract attributes from $request
        $checkoutVariantIDs = $request->checkout_product_variant_ids;
        $voucherCode = $request->voucher_code;
        $deliveryInfo = $request->delivery_info;

        $courierID = $deliveryInfo['method'] === OrderDeliveryMethod::DELIVERY->value ?
            $deliveryInfo['courier_id'] :
            null;

        // Get ShoppingCartItem data
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

        // Service Fee
        $totalPrice = $calculation['price']['total'];
        $serviceFee = $request->input('fixed_fee', 0) + $totalPrice * $request->input('variable_fee', 0);
        $totalPricePlusServiceFee =  $totalPrice + $serviceFee;

        $calculation['service_fee'] = number_format($serviceFee, 2, '.', '');
        $calculation['total_price_plus_service_fee'] = number_format($totalPricePlusServiceFee, 2, '.', '');

        $isEnoughMembershipPoints = $this->checkMembershipPoints($customer, $calculation['point']['total'], $now);

        return [
            'cart_items' => $cartItems,
            'gift_items' => $giftItems,
            'discounts' => $this->formatDiscounts($discounts),
            'calculations' => $calculation,
            'is_voucher_applied' => !is_null($discounts['voucher']),
            'is_enough_membership_points' => $isEnoughMembershipPoints
        ];
    }
}
