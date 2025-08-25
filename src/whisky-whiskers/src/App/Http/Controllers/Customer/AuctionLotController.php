<?php

namespace Starsnet\Project\WhiskyWhiskers\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

// Enums
use App\Enums\Status;

// Models
use App\Models\Configuration;
use App\Models\Customer;
use App\Models\WishlistItem;
use Starsnet\Project\WhiskyWhiskers\App\Models\AuctionLot;
use Starsnet\Project\WhiskyWhiskers\App\Models\Bid;
use Starsnet\Project\WhiskyWhiskers\App\Models\BidHistory;

class AuctionLotController extends Controller
{
    public function getAuctionLotDetails(Request $request): AuctionLot
    {
        /** @var ?AuctionLot $auctionLot */
        $auctionLot = AuctionLot::with([
            'product',
            'productVariant',
            'store',
            'bids'
        ])
            ->find($request->route('auction_lot_id'));
        if (is_null($auctionLot)) abort(404, 'Auction not found');

        if (!in_array($auctionLot->status, [Status::ACTIVE->value, Status::ARCHIVED->value])) {
            abort(404, 'Auction is not available for public');
        }

        // Get isLiked 
        $auctionLot->is_liked = WishlistItem::where([
            'customer_id' => $this->customer()->id,
            'store_id' => $auctionLot->store_id,
            'product_id' => $auctionLot->product_id,
        ])
            ->exists();

        // Get current_bid
        $auctionLot->current_bid = $auctionLot->getCurrentBidPrice();
        $auctionLot->is_reserve_price_met = $auctionLot->current_bid >= $auctionLot->reserve_price;
        $auctionLot->setHidden(['reserve_price']);

        return $auctionLot;
    }

    public function getAllOwnedAuctionLots(): Collection
    {
        /** @var Collection $auctionLots */
        $auctionLots = AuctionLot::where('owned_by_customer_id', $this->customer()->id)
            ->where('status', '!=', Status::DELETED->value)
            ->with([
                'product',
                'productVariant',
                'store',
                'latestBidCustomer',
                'winningBidCustomer'
            ])->get();

        foreach ($auctionLots as $auctionLot) {
            $auctionLot->current_bid = $auctionLot->getCurrentBidPrice();
        }

        return $auctionLots;
    }

    public function getAllParticipatedAuctionLots(): Collection
    {
        $customerId = $this->customer()->id;

        /** @var Collection $auctionLots */
        $auctionLots = AuctionLot::whereHas('bids', function ($query2) use ($customerId) {
            return $query2->where('customer_id', $customerId);
        })
            ->where('status', '!=', Status::DELETED->value)
            ->with([
                'product',
                'productVariant',
                'store',
                'winningBidCustomer'
            ])
            ->get();

        foreach ($auctionLots as $auctionLot) {
            $auctionLot->current_bid = $auctionLot->getCurrentBidPrice();
        }

        return $auctionLots;
    }

    public function getBiddingHistory(Request $request): array
    {
        /** @var ?AuctionLot $auctionLot */
        $auctionLot = AuctionLot::find($request->route('auction_lot_id'));
        if (is_null($auctionLot)) abort(404, 'Auction Lot not found');

        // Get Bid History
        $currentBid = $auctionLot->getCurrentBidPrice();

        $bidHistory = BidHistory::where('auction_lot_id', $auctionLot->id)->first();
        if ($bidHistory == null) {
            $bidHistory = BidHistory::create([
                'auction_lot_id' => $auctionLot->id,
                'current_bid' => $currentBid,
                'histories' => []
            ]);
        }
        $displayBidRecords = $bidHistory['histories'];

        // Attach customer and account information to each bid
        foreach ($displayBidRecords as $bid) {
            $winningBidCustomerID = $bid['winning_bid_customer_id'];
            $winningCustomer = Customer::find($winningBidCustomerID);
            $account = $winningCustomer->account;
            $bid->username = optional($account)->username;
            $bid->avatar = optional($account)->avatar;
        }

        return $displayBidRecords;
    }

    public function createMaximumBid(Request $request): JsonResponse
    {
        $now = now();

        // Extract attributes from $request
        $auctionLotId = $request->route('auction_lot_id');
        $requestedBid = $request->bid;

        // Check auction lot
        /** @var AuctionLot $auctionLot */
        $auctionLot = AuctionLot::find($auctionLotId);
        if (is_null($auctionLot)) abort(404, 'Auction Lot not found');
        if ($auctionLot->status == Status::DELETED->value) abort(404, 'Auction Lot not found');
        if ($auctionLot->status == Status::ARCHIVED->value) abort(403, 'Auction Lot has been archived');
        if ($auctionLot->owned_by_customer_id == $this->customer()->id) abort(400, 'You cannot place bid on your own auction lot');

        // Check time
        $store = $auctionLot->store;
        if ($store->status == Status::DELETED->value) abort(404,  'Auction not found');
        if ($store->status == Status::ARCHIVED->value) abort(403, 'Auction has been archived');

        if ($now <= Carbon::parse($store->start_datetime)) {
            return response()->json([
                'message' => 'The auction id: ' . $store->_id . ' has not yet started.',
                'error_status' => 2,
                'system_time' => now(),
                'auction_start_datetime' => Carbon::parse($store->start_datetime)
            ], 400);
        }

        if ($now > Carbon::parse($store->end_datetime)) {
            return response()->json([
                'message' => 'The auction id: ' . $store->_id . ' has already ended.',
                'error_status' => 3,
                'system_time' => now(),
                'auction_end_datetime' => Carbon::parse($store->end_datetime)
            ], 400);
        }

        // Get current bid
        $customer = $this->customer();
        $biddingIncrementRules = Configuration::where('slug', 'bidding-increments')->latest()->first();
        $currentBid = $auctionLot->getCurrentBidPrice();
        $isBidPlaced = $auctionLot->is_bid_placed;

        // Get bidding increment, and valid minimum bid 
        $biddingIncrementValue = 0;

        if ($isBidPlaced == true) {
            $range = $biddingIncrementRules->bidding_increments;
            foreach ($range as $key => $interval) {
                if ($currentBid >= $interval['from'] && $currentBid < $interval['to']) {
                    $biddingIncrementValue = $interval['increment'];
                    break;
                }
            }
        }

        $minimumBid = $currentBid + $biddingIncrementValue;

        if ($minimumBid > $request->bid) {
            return response()->json([
                'message' => 'Your bid is lower than current valid bid ' .  $minimumBid . '.',
                'error_status' => 0,
                'bid' => $minimumBid
            ], 400);
        }

        // Get user's current largest bid
        $userExistingMaximumBid = Bid::where('auction_lot_id', $auctionLotId)
            ->where('customer_id', $customer->_id)
            ->where('is_hidden',  false)
            ->orderBy('bid', 'desc')
            ->first();

        // Determine minimum possible bid for input from Customer
        if (!is_null($userExistingMaximumBid)) {
            $userMaximumBidValue = $userExistingMaximumBid->bid;

            if ($request->bid <= $userMaximumBidValue) {
                return response()->json([
                    'message' => 'Your bid cannot be lower than or equal to your maximum bid value of ' . $userMaximumBidValue . '.',
                    'error_status' => 1,
                    'bid' => $userMaximumBidValue
                ], 400);
            }
        }

        // Create Bid
        $bid = Bid::create([
            'auction_lot_id' => $auctionLotId,
            'customer_id' => $customer->_id,
            'store_id' => $auctionLot->store_id,
            'product_id' => $auctionLot->product_id,
            'product_variant_id' => $auctionLot->product_variant_id,
            'bid' => $requestedBid
        ]);

        $auctionLotMaximumBid = Bid::where('auction_lot_id', $auctionLotId)
            ->where('is_hidden',  false)
            ->orderBy('bid', 'desc')
            ->first();

        $winningCustomerID = null;
        if (!is_null($auctionLotMaximumBid)) {
            $winningCustomerID = $auctionLotMaximumBid->customer_id;
        }

        $newCurrentBid = $auctionLot->getCurrentBidPrice(
            true,
            $customer->_id,
            $bid->bid
        );
        $auctionLot->update([
            'is_bid_placed' => true,
            'current_bid' => $newCurrentBid,
            'latest_bid_customer_id' => $customer->_id,
            'winning_bid_customer_id' => $winningCustomerID
        ]);

        // Create Bid History Record
        if ($isBidPlaced == false || $newCurrentBid > $currentBid) {
            $bidHistory = BidHistory::where('auction_lot_id', $auctionLotId)->first();
            if ($bidHistory == null) {
                $bidHistory = BidHistory::create([
                    'auction_lot_id' => $auctionLotId,
                    'current_bid' => $newCurrentBid,
                    'histories' => []
                ]);
            }

            $bidHistoryItemAttributes = [
                'winning_bid_customer_id' => $winningCustomerID,
                'current_bid' => $newCurrentBid
            ];
            $bidHistory->histories()->create($bidHistoryItemAttributes);
            $bidHistory->update(['current_bid' => $newCurrentBid]);
        }

        // Extend endDateTime
        $gracePeriodInMins = 15;
        $currentEndDateTime = Carbon::parse($store->end_datetime);
        $graceEndDateTime = $currentEndDateTime->copy()->subMinutes($gracePeriodInMins);

        if ($now > $graceEndDateTime && $now < $currentEndDateTime) {
            $newEndDateTime = $currentEndDateTime->copy()->addMinutes($gracePeriodInMins);
            $store->update([
                'end_datetime' => $newEndDateTime
            ]);
        }

        // Socket
        if ($isBidPlaced == false || $newCurrentBid > $currentBid) {
            try {
                $url = 'https://socket.whiskywhiskers.com/api/publish';
                $data = [
                    "site" => 'whisky-whiskers',
                    "room" => $auctionLotId,
                    "message" => [
                        "bidPrice" => $newCurrentBid,
                        "lotId" => $auctionLotId,
                    ]
                ];
                Http::post($url, $data);
            } catch (\Exception $e) {
                print($e);
            }
        }

        return response()->json([
            'message' => 'Created New maximum Bid successfully',
            '_id' => $bid->id
        ]);
    }
}
