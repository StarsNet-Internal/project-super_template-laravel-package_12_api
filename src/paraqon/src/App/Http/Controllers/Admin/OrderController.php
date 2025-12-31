<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

// Enums
use App\Enums\CheckoutApprovalStatus;
use App\Enums\CheckoutType;
use App\Enums\ShipmentDeliveryStatus;
use App\Enums\Status;

// Models
use App\Models\Checkout;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Collection;
use Starsnet\Project\Paraqon\App\Models\AuctionLot;
use Starsnet\Project\Paraqon\App\Models\AuctionRegistrationRequest;

class OrderController extends Controller
{
    public function getAllAuctionOrders(Request $request): Collection
    {
        // Extract attributes from $request
        $storeID = $request->store_id;
        $customerID = $request->customer_id;

        // Get Order
        $orders = Order::where('is_system', $request->boolean('is_system', true))
            ->when($storeID, function ($query, $storeID) {
                return $query->where('store_id', $storeID);
            })
            ->when($customerID, function ($query, $customerID) {
                return $query->where('customer_id', $customerID);
            })
            ->with(['store'])
            ->get();

        foreach ($orders as $order) {
            $order->checkout = $order->checkout()->latest()->first();

            // Handle image directly from cart_items
            $order->image = null;
            if (!empty($order->cart_items) && count($order->cart_items) > 0) {
                foreach ($order->cart_items as $item) {
                    if (!empty($item['image'])) {
                        $order->image = $item['image'];
                        break; // Stop after finding first image
                    }
                }
            }
        }

        return $orders;
    }

    public function updateOrderDetails(Request $request): array
    {
        /** @var ?Order $order */
        $order = Order::find($request->route('order_id'));
        if (is_null($order)) abort(404, 'Order not found');

        // Update Order
        $order->update($request->all());

        return ['message' => "Updated Order Successfully"];
    }

    public function approveOrderOfflinePayment(Request $request): array
    {
        $status = $request->status;
        if (!in_array($status, CheckoutApprovalStatus::values()))  abort(400, 'Invalid status');

        /** @var ?Order $order */
        $order = Order::find($request->route('id'));
        if (is_null($order)) abort(404, 'Order not found');

        // Get latest Checkout, then validate
        /** @var ?Checkout $checkout */
        $checkout = $order->checkout()->latest()->first();
        if (is_null($checkout)) abort(404, 'Checkout not found');
        if ($checkout->hasApprovedOrRejected()) abort(404, 'Checkout has been ' . $checkout->status);;

        // Create CheckoutApproval
        $checkout->createApproval($status, $request->reason, $this->user());

        if ($status === CheckoutApprovalStatus::APPROVED->value) {
            $productIDs = collect($order->cart_items)->pluck('product_id')->all();

            AuctionLot::where('store_id', $order->store_id)
                ->whereIn('product_id', $productIDs)
                ->update(['is_paid' => true]);

            Product::whereIn('_id', $productIDs)->update([
                'owned_by_customer_id' => $order->customer_id,
                'status' => Status::ACTIVE->value,
                'listing_status' => 'ALREADY_CHECKOUT'
            ]);
        }

        return ['message' => 'Reviewed Order successfully'];
    }

    public function uploadPaymentProofAsCustomer(Request $request): array
    {
        $order = Order::find($request->route('order_id'));
        if (is_null($order)) abort(404, 'Order not found');

        // Get Checkout
        /** @var Checkout $checkout */
        $checkout = $order->checkout()->latest()->first();
        if ($checkout->payment_method != CheckoutType::OFFLINE->value) abort(403, 'Order does not accept OFFLINE payment');

        // Update Checkout
        $checkout->update([
            'offline' => [
                'image' => $request->image,
                'uploaded_at' => now(),
                'api_response' => null
            ]
        ]);

        return ['message' => 'Uploaded image successfully'];
    }

    public function getInvoiceData(Request $request)
    {
        $document = Order::find($request->route('id'));
        $store = $document->store;

        return isset($store->auction_type)
            ? $this->getAuctionInvoiceData($request)
            : $this->getPrivateSaleInvoiceData($request);
    }

    public function getAuctionInvoiceData(Request $request)
    {
        $orderId = $request->route('id');
        $language = $request->route('language');

        $document = Order::find($orderId);
        if (is_null($document)) abort(404, 'Order not found');

        $storeId = $document->store_id;
        $customerId = $document->customer_id;

        $store = $document->store;
        $storeName = $store->title[$language];
        $invoicePrefix = $store->invoice_prefix ?? 'OA1';

        $customer = $document->customer;
        $account = $customer->account;

        // Get AuctionRegistrationRequest
        $auctionRegistrationRequest = AuctionRegistrationRequest::where('store_id', $storeId)
            ->where('requested_by_customer_id', $customerId)
            ->first();
        $paddleId = $auctionRegistrationRequest->paddle_id;

        // Construct Store Name
        $dateText = $this->formatDateRange($store->start_datetime, $store->display_end_datetime);
        $storeNameText = "{$storeName} {$dateText}";

        // Get buyerName
        $buyerName = $account->username;

        if (!empty($account->legal_name_verification['name'])) {
            $buyerName = $account->legal_name_verification['name'];
        } else if (!$document->is_system) {
            $deliveryDetails = data_get($document, 'delivery_details');
            if ($deliveryDetails && (is_object($deliveryDetails) || is_array($deliveryDetails))) {
                $recipientName = data_get($deliveryDetails, 'recipient_name');
                if ($recipientName && (is_object($recipientName) || is_array($recipientName))) {
                    $firstName = data_get($recipientName, 'first_name', '');
                    $lastName = data_get($recipientName, 'last_name', '');
                    if ($lastName || $firstName) {
                        if ($lastName && $firstName) {
                            $buyerName = "{$lastName}, {$firstName}";
                        } elseif ($lastName) {
                            $buyerName = $lastName;
                        } elseif ($firstName) {
                            $buyerName = $firstName;
                        }
                    }
                }
            }
        }

        // Format data
        $issueDate = Carbon::parse($document->created_at)->addHours(8);
        $formattedIssueDate = $issueDate->format('d/m/Y');

        $itemsData = collect($document->cart_items)->map(function ($item) use ($language) {
            $hammerPrice = number_format($item->winning_bid, 2, '.', ',');
            $commission = number_format($item->commission ?? 0, 2, '.', ',');
            $otherFees = number_format(0, 2, '.', ',');
            $totalOrSumValue = $item->sold_price ?? $item->winning_bid;
            $totalOrSum = number_format($totalOrSumValue, 2, '.', ',');

            return [
                'lotNo' => $item->lot_number,
                'lotImage' => $item->image,
                'description' => $item->product_title[$language],
                'hammerPrice' => $hammerPrice,
                'commission' => $commission,
                'otherFees' => $otherFees,
                'totalOrSum' => $totalOrSum,
            ];
        });

        $total = (float) $document->calculations['price']['total'];
        $deposit = (float) $document->calculations['deposit'];
        $totalPrice = is_nan($total) || is_nan($deposit) ? NAN : number_format($total + $deposit, 2, '.', ',');
        $totalPriceText = ($document->payment_method == "ONLINE" && $invoicePrefix == 'OA1')
            ? "{$totalPrice} (includes credit card charge of 3.5%)"
            : $totalPrice;
        $depositText = number_format($deposit, 2, '.', ',');
        $formattedDepositText = $deposit > 0 ? "($depositText)" : $depositText;
        $amountPayableText = number_format($total, 2, '.', ',');

        // Construct entire data
        $newCustomerId = substr($customerId, -6);
        $invoiceId = "{$invoicePrefix}-{$paddleId}";

        $data = [
            'lang' => $language,
            'buyerName' => $buyerName,
            'date' => $formattedIssueDate,
            'clientNo' => $newCustomerId,
            'paddleNo' => "#{$paddleId}",
            'auctionTitle' => $storeNameText,
            'shipTo' => "In-store pick up",
            'invoiceNum' => $invoiceId,
            'items' => $itemsData,
            'tableTotal' => $totalPriceText,
            'tableDeposit' => $formattedDepositText,
            'tableAmountPayable' => $amountPayableText,
        ];

        return [
            'model' => 'INVOICE',
            'type' => 'Buyer',
            'data' => $data
        ];
    }

    public function getPrivateSaleInvoiceData(Request $request)
    {
        $orderId = $request->route('id');
        $language = $request->route('language');

        $document = Order::find($orderId);
        if (is_null($document)) abort(404, 'Order not found');

        $customerId = $document->customer_id;

        $store = $document->store;
        $storeName = $store->title[$language];
        $invoicePrefix = $store->invoice_prefix ?? 'OA1';

        $customer = $document->customer;
        $account = $customer->account;

        // Construct Store Name
        $storeNameText = $storeName;

        // Get buyerName
        $buyerName = $account->username;

        if (!empty($account->legal_name_verification['name'])) {
            $buyerName = $account->legal_name_verification['name'];
        } else if (!$document->is_system) {
            $deliveryDetails = data_get($document, 'delivery_details');
            if ($deliveryDetails && (is_object($deliveryDetails) || is_array($deliveryDetails))) {
                $recipientName = data_get($deliveryDetails, 'recipient_name');
                if ($recipientName && (is_object($recipientName) || is_array($recipientName))) {
                    $firstName = data_get($recipientName, 'first_name', '');
                    $lastName = data_get($recipientName, 'last_name', '');
                    if ($lastName || $firstName) {
                        if ($lastName && $firstName) {
                            $buyerName = "{$lastName}, {$firstName}";
                        } elseif ($lastName) {
                            $buyerName = $lastName;
                        } elseif ($firstName) {
                            $buyerName = $firstName;
                        }
                    }
                }
            }
        }

        // Format data
        $issueDate = Carbon::parse($document->created_at)->addHours(8);
        $formattedIssueDate = $issueDate->format('d/m/Y');

        $itemsData = collect($document->cart_items)->map(function ($item) use ($language) {
            // Calculate values with fallbacks
            $hammerPrice = number_format((float) $item->subtotal_price, 2, '.', ',');
            $commission = number_format(0, 2, '.', ',');
            $otherFees = number_format(0, 2, '.', ',');
            $totalOrSumValue = $hammerPrice;

            $totalOrSum = $totalOrSumValue;

            return [
                'lotNo' => $item->stock_no,
                'lotImage' => $item->image,
                'description' => $item->product_title[$language],
                'hammerPrice' => $hammerPrice,
                'commission' => $commission,
                'otherFees' => $otherFees,
                'totalOrSum' => $totalOrSum,
            ];
        });

        $subTotal = (float) $document->calculations['price']['total'];
        $extraCharge = (float) $document->change;
        $discount = (float) $document->discount;
        $tableTotal = (float) $document->amount_received;

        // Construct entire data
        $invoiceId = "{$invoicePrefix}-" . substr($orderId, -6);

        $data = [
            'lang' => $language,
            'buyerName' => $buyerName,
            'date' => $formattedIssueDate,
            'clientNo' => $account->client_no ?? substr($customerId, -6),
            'auctionTitle' => $storeNameText,
            'shipTo' => "In-store pick up",
            'invoiceNum' => $invoiceId,
            'items' => $itemsData,
            'subTotal' => $subTotal,
            'extraCharge' => $extraCharge,
            'discount' => $discount,
            'tableTotal' => $tableTotal,
        ];

        return [
            'model' => 'PRIVATE_SALE_INVOICE',
            'type' => 'Buyer',
            'data' => $data
        ];
    }

    private function formatDateRange(
        string $startDateTime,
        string $endDateTime
    ): string {
        if (!$startDateTime || !$endDateTime) return "";

        $start = Carbon::parse($startDateTime)->utc();
        $end = Carbon::parse($endDateTime)->utc();

        if ($start->format('M') === $end->format('M') && $start->year === $end->year) {
            return $start->format('d') . '-' . $end->format('d') . ' ' . $start->format('M Y');
        } elseif ($start->year === $end->year) {
            return $start->format('d M') . ' - ' . $end->format('d M Y');
        }
        return $start->format('d M Y') . ' - ' . $end->format('d M Y');
    }

    public function cancelOrderPayment(Request $request)
    {
        /** @var ?Order $order */
        $order = Order::find($request->route('order_id'));
        if (is_null($order)) abort(404, 'Order not found');
        if ($order->customer_id !== $this->customer()->id) abort(403, 'Order does not belong to you');
        if (is_null($order->scheduled_payment_at)) abort(400, 'Order does not have scheduled_payment_at');
        if (now()->gt($order->scheduled_payment_at)) abort(403, 'The scheduled payment time has already passed');

        /** @var ?Checkout $checkout */
        $checkout = $order->checkout()->latest()->first();
        $paymentIntentID = $checkout->online['payment_intent_id'];

        $url = env('PARAQON_STRIPE_BASE_URL', 'https://payment.paraqon.starsnet.hk') . '/payment-intents/' . $paymentIntentID . '/cancel';
        $response = Http::post($url);

        if ($response->status() === 200) {
            $order->updateStatus(ShipmentDeliveryStatus::CANCELLED->values);
            $checkout->updateApprovalStatus(CheckoutApprovalStatus::REJECTED->values);
            return ['message' => 'Update Order status as cancelled'];
        }

        return response()->json(['message' => 'Unable to cancel payment from Stripe, paymentIntent might have been closed']);
    }
}
