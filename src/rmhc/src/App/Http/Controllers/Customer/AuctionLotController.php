<?php

namespace Starsnet\Project\Rmhc\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Carbon\Carbon;

// Enums
use App\Enums\Status;

// Models
use Starsnet\Project\Paraqon\App\Models\AuctionLot;
use Starsnet\Project\Paraqon\App\Models\Bid;
use Starsnet\Project\Paraqon\App\Models\BidHistory;
use Starsnet\Project\Paraqon\App\Models\WatchlistItem;
use Starsnet\Project\Rmhc\App\Models\WinningCustomerHistory;

class AuctionLotController extends Controller
{
    public function getAuctionLotDetails(Request $request): AuctionLot
    {
        /** @var ?AuctionLot $auctionLot */
        $auctionLot = AuctionLot::with(['product', 'productVariant', 'store', 'bids'])->find($request->route('auction_lot_id'));
        if (is_null($auctionLot)) abort(404, 'AuctionLot not found');
        if (!in_array($auctionLot->status, [Status::ACTIVE->value, Status::ARCHIVED->value])) abort(404, 'Auction is not available for public');

        // Append keys
        $auctionLot->is_liked = false;
        $auctionLot->current_bid = $auctionLot->getCurrentBidPrice();
        $auctionLot->is_reserve_price_met = $auctionLot->current_bid >= $auctionLot->reserve_price;

        // Get Watching Lots
        $customer = $this->customer();
        $watchingAuctionIDs = WatchlistItem::where('customer_id', $customer->id)
            ->where('item_type', 'auction-lot')
            ->get()
            ->pluck('item_id')
            ->all();
        $auctionLot->is_watching = in_array($auctionLot->id, $watchingAuctionIDs);

        // Get winning_customer_ids
        $winnerHistory = WinningCustomerHistory::where('auction_lot_id', $auctionLot->id)
            ->latest()
            ->first();

        $winningCustomerIDs = collect($winnerHistory?->winning_customers ?? [])
            ->pluck('customer_id')
            ->filter()
            ->values()
            ->toArray();

        $auctionLot->winning_bid_customer_ids = $winningCustomerIDs;

        return $auctionLot;
    }


    public function getAllParticipatedAuctionLots(): Collection
    {
        $customer = $this->customer();
        $auctionLots = AuctionLot::with([
            'product',
            'productVariant',
            'store',
            'winningBidCustomer'
        ])
            ->whereHas(
                'bids',
                function ($query2) use ($customer) {
                    return $query2->where('customer_id', $customer->id);
                }
            )
            ->where('status', '!=', Status::DELETED->value)
            ->get();

        $latestHistories = WinningCustomerHistory::query()
            ->whereIn('auction_lot_id', $auctionLots->pluck('id')->all())
            ->orderByDesc('created_at')
            ->get()
            ->unique('auction_lot_id')
            ->keyBy('auction_lot_id')
            ->toArray();

        // Calculate highest bid
        foreach ($auctionLots as $auctionLot) {
            $auctionLot->current_bid = $auctionLot->getCurrentBidPrice();

            $winnerHistory = $latestHistories[$auctionLot->id] ?? null;
            $winningCustomerIDs = collect($winnerHistory['winning_customers'] ?? [])
                ->pluck('customer_id')
                ->filter()
                ->values()
                ->toArray();

            $auctionLot->winning_bid_customer_ids = $winningCustomerIDs;
        }

        return $auctionLots;
    }

    public function createMaximumBid(Request $request)
    {
        $now = now();

        // Extract attributes from $request
        $auctionLotId = $request->route('auction_lot_id');
        $requestedBid = $request->bid;
        $bidType = $request->input('type', 'MAX');
        $isPlacedByAdmin = $request->boolean('is_placed_by_admin', false);
        $customer = $this->customer();

        // Validation for the request body
        if (!in_array($bidType, ['MAX', 'DIRECT', 'ADVANCED'])) abort(400, 'Invalid bid type');

        /** @var ?AuctionLot $auctionLot */
        $auctionLot = AuctionLot::find($auctionLotId);
        if (is_null($auctionLot)) abort(404, 'AuctionLot not found');
        if ($auctionLot->status == Status::DELETED->value) abort(404, 'AuctionLot not found');
        if ($auctionLot->owned_by_customer_id == $customer->id) abort(404, 'You cannot place bid on your own auction lot');

        /** @var ?Store $store */
        $store = $auctionLot->store;
        if ($store->status === Status::DELETED->value) abort(404, 'Auction not found');

        // Check winner
        $bidHistory = BidHistory::where('auction_lot_id', $auctionLotId)->first();
        if (!is_null($bidHistory) && $bidType == 'DIRECT') {
            if ($bidHistory->histories()->count() > 0) {
                $lastItem = $bidHistory->histories()->last();
                $winningBidCustomerID = $lastItem->winning_bid_customer_id;

                if ($winningBidCustomerID == $this->customer()->_id) {
                    return response()->json([
                        'message' => 'You cannot place bid on the lot you are already winning',
                        'error_status' => 4
                    ], 404);
                }
            }
        }

        // Get current_bid price
        $customer = $this->customer();
        $currentBid = $auctionLot->getCurrentBidPrice();
        $isBidPlaced = $auctionLot->is_bid_placed;

        if (in_array($bidType, ['MAX', 'DIRECT'])) {
            if ($auctionLot->status == Status::ARCHIVED->value) abort(404, 'AuctionLot has been archived');
            if ($store->status == Status::ARCHIVED->value) abort(404, 'Auction has been found');

            // Check if this MAX or DIRECT bid place after start_datetime
            if ($now <= Carbon::parse($store->start_datetime)) {
                return response()->json([
                    'message' => 'The auction id: ' . $store->_id . ' has not yet started.',
                    'error_status' => 2,
                    'system_time' => $now,
                    'auction_start_datetime' => Carbon::parse($store->start_datetime)
                ], 400);
            }

            // Check if this MAX or DIRECT bid place before end_datetime
            if ($now > Carbon::parse($store->end_datetime)) {
                return response()->json([
                    'message' => 'The auction id: ' . $store->_id . ' has already ended.',
                    'error_status' => 3,
                    'system_time' => $now,
                    'auction_end_datetime' => Carbon::parse($store->end_datetime)
                ], 400);
            }

            // Get bidding increment, and valid minimum bid 
            $incrementRules = optional($auctionLot->bid_incremental_settings)['increments'];
            $biddingIncrementValue = 0;

            if ($isBidPlaced == true) {
                foreach ($incrementRules as $interval) {
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
                ->where('type', $bidType)
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
        }

        // Hide previous placed ADVANCED bid, if there's any
        if ($bidType == 'ADVANCED') {
            if ($auctionLot->status == Status::ACTIVE->value) abort(404, 'AuctionLot is now status ACTIVE, no longer accept any ADVANCED bids');

            // Check if this MAX or DIRECT bid place after start_datetime
            if ($now >= Carbon::parse($store->start_datetime)) {
                return response()->json([
                    'message' => 'Auction has started, no longer accept any ADVANCED bids',
                    'error_status' => 4,
                    'system_time' => $now,
                    'auction_start_datetime' => Carbon::parse($store->start_datetime)
                ], 400);
            }

            Bid::where('auction_lot_id', $auctionLotId)
                ->where('customer_id', $customer->_id)
                ->where('is_hidden', false)
                ->update(['is_hidden' => true]);
        }

        // Create Bid
        $bid = Bid::create([
            'auction_lot_id' => $auctionLotId,
            'customer_id' => $customer->_id,
            'store_id' => $auctionLot->store_id,
            'product_id' => $auctionLot->product_id,
            'product_variant_id' => $auctionLot->product_variant_id,
            'bid' => $requestedBid,
            'type' => $bidType,
            'is_placed_by_admin' => $isPlacedByAdmin
        ]);

        $newCurrentBid = null;
        // Update current_bid
        if (in_array($bidType, ['MAX', 'DIRECT'])) {
            // Extend AuctionLot endDateTime
            $currentLotEndDateTime = Carbon::parse($auctionLot->end_datetime);
            $newLotEndDateTime = $currentLotEndDateTime;

            $addExtendDays = $auctionLot->auction_time_settings['extension']['days'];
            $addExtendHours = $auctionLot->auction_time_settings['extension']['hours'];
            $addExtendMins = $auctionLot->auction_time_settings['extension']['mins'];

            $extendLotDeadline = $currentLotEndDateTime->copy()
                ->subDays($addExtendDays)
                ->subHours($addExtendHours)
                ->subMinutes($addExtendMins);

            if ($now >= $extendLotDeadline && $now < $currentLotEndDateTime) {
                $addMaxDays = $auctionLot->auction_time_settings['allow_duration']['days'];
                $addMaxHours = $auctionLot->auction_time_settings['allow_duration']['hours'];
                $addMaxMins = $auctionLot->auction_time_settings['allow_duration']['mins'];

                $newEndDateTime = $currentLotEndDateTime->copy()
                    ->addDays($addExtendDays)
                    ->addHours($addExtendHours)
                    ->addMinutes($addExtendMins);

                $maxEndDateTime = $currentLotEndDateTime->copy()
                    ->addDays($addMaxDays)
                    ->addHours($addMaxHours)
                    ->addMinutes($addMaxMins);

                $newLotEndDateTime = $newEndDateTime >= $maxEndDateTime
                    ? $maxEndDateTime :
                    $newEndDateTime;

                $auctionLot->update([
                    'end_datetime' => $newLotEndDateTime->toISOString()
                ]);
            }

            // Update current_bid
            // Find winningCustomerID
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
                $bid->customer_id,
                $bid->bid,
                $bid->type
            );

            $auctionLot->update([
                'is_bid_placed' => true,
                'current_bid' => $newCurrentBid,
                'latest_bid_customer_id' => $customer->_id,
                'winning_bid_customer_id' => $winningCustomerID,
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

            // Extend Store endDateTime
            $currentStoreEndDateTime = Carbon::parse($store->end_datetime);
            if ($newLotEndDateTime > $currentStoreEndDateTime) {
                $store->update([
                    'end_datetime' => $newLotEndDateTime->toISOString()
                ]);
            }
        }

        if ($bidType == 'ADVANCED') {
            $bidHistory = BidHistory::where('auction_lot_id', $auctionLotId)->first();
            if ($bidHistory == null) {
                $bidHistory = BidHistory::create([
                    'auction_lot_id' => $auctionLotId,
                    'current_bid' => $auctionLot->starting_price,
                    'histories' => []
                ]);
            }

            // get current bid and winner
            $newCurrentBid = $auctionLot->getCurrentBidPrice(
                true,
                $bid->customer_id,
                $bid->bid,
                $bid->type
            );

            // Find winningCustomerID
            $auctionLotMaximumBid = Bid::where('auction_lot_id', $auctionLotId)
                ->where('is_hidden',  false)
                ->orderBy('bid', 'desc')
                ->first();

            $winningCustomerID = null;
            if (!is_null($auctionLotMaximumBid)) {
                $winningCustomerID = $auctionLotMaximumBid->customer_id;
            }

            // Update BidHistory
            $bidHistory->update([
                'current_bid' => $auctionLot->starting_price,
                'histories' => []
            ]);
            $bidHistoryItemAttributes = [
                'winning_bid_customer_id' => $winningCustomerID,
                'current_bid' => $newCurrentBid
            ];
            $bidHistory->histories()->create($bidHistoryItemAttributes);
            $bidHistory->update(['current_bid' => $newCurrentBid]);

            // Update Auction Lot
            $auctionLot->update([
                'is_bid_placed' => true,
                'current_bid' => $newCurrentBid,
                'latest_bid_customer_id' => $winningCustomerID,
                'winning_bid_customer_id' => $winningCustomerID,
            ]);
        }

        // Create WinningBidCustomerID
        $winnerCount = $auctionLot->winner_count ?? 1;
        $winningHistories = [
            [
                'customer_id' => $winningCustomerID,
                'winning_bid' => $newCurrentBid
            ]
        ];
        $winningCustomerIDs = [$winningCustomerID];

        $otherBids = Bid::where('auction_lot_id', $auctionLot->id)
            ->where('customer_id', '!=', $winningCustomerID)
            ->orderByDesc('bid')
            ->orderBy('created_at')
            ->get();

        foreach ($otherBids as $bid) {
            if (count($winningHistories) >= $winnerCount) break;
            if (in_array($bid->customer_id, $winningCustomerIDs)) continue;

            $winningHistories[] = [
                'customer_id' => $bid->customer_id,
                'winning_bid' => $bid->bid
            ];
            $winningCustomerIDs[] = $bid->customer_id;
        }

        WinningCustomerHistory::create([
            'auction_lot_id' => $auctionLot->id,
            'winning_customers' => $winningHistories,
            'confirmed_winner_count' => count($winningHistories),
            'auction_lot_winner_count' => $auctionLot->winner_count ?? 1,
        ]);

        return response()->json([
            'message' => 'Created New maximum Bid successfully',
            '_id' => $bid->_id
        ], 200);
    }
}
