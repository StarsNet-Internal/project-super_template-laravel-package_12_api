<?php

namespace Starsnet\Project\Rmhc\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// Enums
use App\Enums\OrderPaymentMethod;

// Models
use App\Models\Alias;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;

class OrderController extends Controller
{
    public function getInvoiceData(Request $request)
    {
        $language = $request->input('language', 'en');

        // Get and validate Customer
        $customerID = $request->input('customer_id');
        $customer = Customer::find($customerID);
        if (is_null($customer)) abort(404, "Customer not found, invalid customer_id given: $customerID");

        $account = $customer->account;
        if (is_null($account)) abort(404, "Account not found, invalid customer_id given: $customerID");

        // Get and validate store_ids
        $auctionStoreID = $request->input('auction_store_id', 'default-auction-store');
        $auctionStore = Store::find($auctionStoreID) ?? Store::find(Alias::getValue($auctionStoreID));
        if (is_null($auctionStore)) abort(404, "Auction Store not found, invalid auction_store_id given: $auctionStoreID");
        $auctionStoreID = $auctionStore->_id;

        $donationStoreID = $request->input('donation_store_id', 'default-donation-store');
        $donationStore = Store::find($donationStoreID) ?? Store::find(Alias::getValue($donationStoreID));
        if (is_null($donationStore)) abort(404, "Donation Store not found, invalid donation_store_id given: $donationStoreID");
        $donationStoreID = $donationStore->_id;

        // Get and validate orders
        $auctionStoreOrder = Order::where('store_id', $auctionStoreID)
            ->where('customer_id', $customerID)
            ->where('is_system', true)
            ->latest()
            ->first();

        $donationStoreOrders = Order::where('store_id', $donationStoreID)
            ->where('customer_id', $customerID)
            ->where('is_system', true)
            ->latest()
            ->get();
        if (is_null($auctionStoreOrder) && $donationStoreOrders->count() === 0) abort(404, 'No relevant orders found for these store_ids and customer');

        // Construct items_data
        $totalAmount = 0;
        $itemsData = [];

        if (!is_null($auctionStoreOrder)) {
            foreach ($auctionStoreOrder->cart_items as $item) {
                $rawAmount = $item['winning_bid'] ?? 0;
                $totalAmount += $rawAmount; // Add up
                $formattedRawAmount = number_format($rawAmount, 2, '.', '');

                $lotNumber = $item['lot_number'];
                $itemName = $item['product_title'][$language];

                $itemsData[] = [
                    'name' => "Lot $lotNumber - $itemName",
                    'amount' => "HKD$ $formattedRawAmount",
                    'toDeliver' => false,
                    'pickUp' => false,
                ];
            }
        }

        if ($donationStoreOrders->count() > 0) {
            foreach ($donationStoreOrders as $order) {
                $rawAmount = $order->calculations['price']['total'];
                $totalAmount += $rawAmount; // Add up
                $formattedRawAmount = number_format($rawAmount, 2, '.', '');

                $itemDescription = "Charitable Donation";
                if ($language == 'zh') $itemDescription = "愛心捐款";
                if ($language == 'cn') $itemDescription = "爱心捐款";

                $itemsData[] = [
                    'name' => $itemDescription,
                    'amount' => "HKD$ $formattedRawAmount",
                    'toDeliver' => false,
                    'pickUp' => false,
                ];
            }
        }

        // Get other metadata
        $formattedTotalAmount = number_format($totalAmount, 2, '.', '');

        // Guest info
        $guestName = $account->first_name . " " . $account->last_name;
        $tableNo = "";
        $nameCardSlot = "";

        // Contact info
        $contactName = $guestName;
        $contactTelephone = trim('+' . trim($account->area_code ?? '852') . ' ' . ($account->phone ?? ''));
        $contactEmail = $account->email ?? "";
        $contactAddress = $customer->delivery_recipient['address'] ?? "";

        // Credit Card Charge info
        $isAutoCharged = Order::whereIn('store_id', [$donationStoreID, $auctionStoreID])
            ->where('customer_id', $customerID)
            ->where('is_system', true)
            ->where('is_paid', true)
            ->where('payment_method', OrderPaymentMethod::ONLINE->value)
            ->exists();

        $cardName = "";
        $expMonth = isset($customer->stripe_card_data['exp_month'])
            ? str_pad((string)$customer->stripe_card_data['exp_month'], 2, '0', STR_PAD_LEFT)
            : '';
        $expYear = $customer->stripe_card_data['exp_year'] ?? '';
        $expiryDate = ($expMonth && $expYear && $isAutoCharged === true)
            ? "{$expMonth}/{$expYear}"
            : '';
        $cardNumber = ($isAutoCharged === true)
            ? ($customer->stripe_card_data['last4'] ?? '')
            : '';
        $cardBrand = ($isAutoCharged === true)
            ? ($customer->stripe_card_data['brand'] ?? null)
            : null;

        return [
            'lang' => $language,
            'guestName' => $guestName,
            'tableNo' => $tableNo,
            'totalAmount' => " {$formattedTotalAmount}",
            'contactName' => $contactName,
            'contactTelephone' => $contactTelephone,
            'contactEmail' => $contactEmail,
            'contactAddress' => $contactAddress,
            'nameCardSlot' => $nameCardSlot,
            'cash' => false,
            'cheque' => false,
            'creditCard' => $isAutoCharged,
            'visa' => $cardBrand === 'visa',
            'masterCard' => $cardBrand === 'mastercard',
            'american' => $cardBrand === 'amex',
            'cardName' => $cardName,
            'expiryDate' => $expiryDate,
            'cardNumber' => $cardNumber,
            'validationCode' => '',
            'cardSignature' => '',
            'donorSignature' => '',
            'staffSignature' => '',
            'items' => $itemsData,
        ];
    }
}
