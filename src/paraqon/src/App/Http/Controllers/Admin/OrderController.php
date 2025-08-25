<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Carbon\Carbon;

// Enums
use App\Enums\CheckoutApprovalStatus;
use App\Enums\CheckoutType;
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

    public function getInvoiceData(Request $request): array
    {
        /** @var ?Order $order */
        $order = Order::find($request->route('id'));
        if (is_null($order)) abort(404, 'Order not found');

        // Get AuctionRegistrationRequest
        $store = $order->store;
        $customer = $order->customer;
        $account = $customer->account;

        /** @var AuctionRegistrationRequest $auctionRegistrationRequest */
        $auctionRegistrationRequest = AuctionRegistrationRequest::where('store_id', $store->id)
            ->where('requested_by_customer_id', $customer->id)
            ->first();

        // Get Store metadata
        $language = $request->route('language');
        $dateText = $this->formatDateRange($store['start_datetime'], $store['display_end_datetime']);
        $auctionTitle = $store['title'][$language] . ' ' . $dateText;

        // Get buyer name
        $buyerName = $account['username'];
        if (
            $order['is_system'] === false &&
            isset($order['delivery_details']['recipient_name'])
        ) {
            $first = $order['delivery_details']['recipient_name']['first_name'] ?? '';
            $last = $order['delivery_details']['recipient_name']['last_name'] ?? '';
            if ($last) $buyerName = "$last, $first";
        }

        $createdAt = Carbon::parse($order['created_at'])->addHours(8);
        $formattedIssueDate = $createdAt->format('d/m/Y');

        $itemsData = collect($order['cart_items'])
            ->map(function ($item) use ($language) {
                $formatted = number_format($item['winning_bid'], 2, '.', ',');
                return [
                    'lotNo' => $item['lot_number'],
                    'lotImage' => $item['image'],
                    'description' => $item['product_title'][$language],
                    'hammerPrice' => $formatted,
                    'commission' => number_format(0, 2, '.', ','),
                    'otherFees' => number_format(0, 2, '.', ','),
                    'totalOrSum' => $formatted
                ];
            })
            ->toArray();

        // Get totalPriceText
        $total = floatval($document['calculations']['price']['total'] ?? 0);
        $deposit = floatval($document['calculations']['deposit'] ?? 0);
        $totalFormatted = number_format($total + $deposit, 2, '.', ',');
        $creditChargeText = $language === 'zh'
            ? "包括3.5%信用卡收費"
            : "Includes 3.5% credit card charge";
        $totalPriceText = $order['payment_method'] === "ONLINE"
            ? "$totalFormatted ($creditChargeText)"
            : $totalFormatted;

        // Get paddle_id
        $paddleID = $auctionRegistrationRequest['paddle_id'];
        $invoiceID = "OA1-{$paddleID}";

        return [
            'model' => "INVOICE",
            'type' => "Buyer",
            'data' => [
                'lang' => $language,
                'buyerName' => $buyerName,
                'date' => $formattedIssueDate,
                'clientNo' => substr($customer->id, -6),
                'paddleNo' => "#$paddleID",
                'auctionTitle' => $auctionTitle,
                'shipTo' => "In-store pick up",
                'invoiceNum' => $invoiceID,
                'items' => $itemsData,
                'tableTotal' => $totalPriceText
            ]
        ];
    }

    private function formatDateRange(
        string $startDateTime,
        string $endDateTime
    ): string {
        if (empty($startDateTime) || empty($endDateTime)) return "";

        try {
            $start = Carbon::parse($startDateTime)->utc();
            $end = Carbon::parse($endDateTime)->utc();

            // Optional: handle end-before-start case
            if ($end->lt($start)) [$start, $end] = [$end, $start];

            if ($start->isSameDay($end) && $start->isSameMonth($end) && $start->isSameYear($end)) return $start->format('d M Y');
            if ($start->isSameMonth($end) && $start->isSameYear($end)) return $start->format('d') . '-' . $end->format('d') . ' ' . $start->format('M Y');
            if ($start->isSameYear($end)) return $start->format('d M') . ' - ' . $end->format('d M Y');
            return $start->format('d M Y') . ' - ' . $end->format('d M Y');
        } catch (\Exception $e) {
            return "";
        }
    }
}
