<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Carbon\Carbon;

// Enums
use App\Enums\Status;

// Models
use App\Models\Customer;
use Starsnet\Project\Paraqon\App\Models\AuctionLot;
use Starsnet\Project\Paraqon\App\Models\Bid;
use Starsnet\Project\Paraqon\App\Models\BidHistory;
use Starsnet\Project\Paraqon\App\Models\WatchlistItem;

class AuctionLotController extends Controller
{
    public function requestForBidPermissions(Request $request): array
    {
        $now = now();

        /** @var AuctionLot $auctionLot */
        $auctionLot = AuctionLot::find($request->route('auction_lot_id'));
        if (is_null($auctionLot)) abort(404, 'Auction Lot not found');
        if (!in_array($auctionLot->status, [Status::ACTIVE->value, Status::ARCHIVED->value])) abort(404, 'Auction Lot not found');
        if ($auctionLot->is_permission_required == false) abort(400, 'Auction Lot does not require permission to place bid');

        // Check if requests exist
        /** @var Collection $allBidRequests */
        $allBidRequests = $auctionLot->permission_requests;
        $customer = $this->customer();
        $isCustomerRequestExists = collect($allBidRequests)
            ->contains(function ($item) use ($customer) {
                return $item['customer_id'] == $customer->_id &&
                    in_array($item['approval_status'], ['PENDING', 'APPROVED']);
            });
        if ($isCustomerRequestExists) abort(400, 'You already have a PENDING or APPROVED request');

        // Update AuctionLot
        $bidRequest = [
            'customer_id' => $customer->id,
            'approval_status' => 'PENDING',
            'created_at' => $now,
            'updated_at' => $now
        ];
        $auctionLot->push('permission_requests', $bidRequest, true);

        return ['message' => 'Request to place bid on this Auction Lot sent successfully'];
    }

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

        return $auctionLot;
    }

    public function getAllAuctionLotBids(Request $request): Collection
    {
        $auctionLot = AuctionLot::find($request->route('auction_lot_id'));
        if (is_null($auctionLot)) abort(404, 'AuctionLot not found');
        if (!in_array($auctionLot->status, [Status::ACTIVE->value, Status::ARCHIVED->value])) abort(404, 'AuctionLot is not available for public');

        $bids = Bid::where('auction_lot_id', $auctionLot->id)
            ->where('is_hidden', false)
            ->latest()
            ->get();

        // Attach customer and account information to each bid
        foreach ($bids as $bid) {
            $customerID = $bid->customer_id;
            $customer = Customer::find($customerID);
            $account = $customer->account;
            $bid->username = optional($account)->username;
            $bid->avatar = optional($account)->avatar;
        }

        return $bids;
    }

    public function getAllOwnedAuctionLots(): Collection
    {
        $auctionLots = AuctionLot::with([
            'product',
            'productVariant',
            'store',
            'latestBidCustomer',
            'winningBidCustomer'
        ])
            ->where('owned_by_customer_id', $this->customer()->id)
            ->where('status', '!=', Status::DELETED->value)
            ->get();

        foreach ($auctionLots as $auctionLot) {
            $auctionLot->current_bid = $auctionLot->getCurrentBidPrice();
        }

        return $auctionLots;
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

        // Calculate highest bid
        foreach ($auctionLots as $auctionLot) {
            $auctionLot->current_bid = $auctionLot->getCurrentBidPrice();
        }

        return $auctionLots;
    }

    public function getBiddingHistory(Request $request): Collection
    {
        // Get Auction Store(s)
        $auctionLot = AuctionLot::find($request->route('auction_lot_id'));
        if (is_null($auctionLot)) abort(404, 'Auction Lot not found');

        // Get Bid History
        $currentBid = $auctionLot->getCurrentBidPrice();
        $bidHistory = BidHistory::where('auction_lot_id', $request->route('auction_lot_id'))->first();
        if ($bidHistory == null) {
            $bidHistory = BidHistory::create([
                'auction_lot_id' => $request->route('auction_lot_id'),
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

        // Return Auction Store
        return response()->json([
            'message' => 'Created New maximum Bid successfully',
            '_id' => $bid->_id
        ], 200);
    }

    public function createLiveBid(Request $request)
    {
        $now = now();

        // Extract attributes from $request
        $auctionLotId = $request->route('auction_lot_id');
        $requestedBid = $request->bid;
        $bidType = $request->input('type', 'MAX');

        // Validation for the request body
        if (!in_array($bidType, ['MAX', 'DIRECT', 'ADVANCED'])) abort(400, 'Invalid type');

        // Check auction lot
        /** @var AuctionLot $auctionLot */
        $auctionLot = AuctionLot::find($auctionLotId);
        if (is_null($auctionLot)) abort(404, 'AuctionLot not found');
        if ($auctionLot->is_disabled == true) abort(404, 'AuctionLot not found');
        if ($auctionLot->status == Status::DELETED->value) abort(404, 'AuctionLot not found');

        $customer = $this->customer();
        if ($auctionLot->owned_by_customer_id == $customer->id) abort(404, 'You cannot place bid on your own auction lot');

        // Check time
        $store = $auctionLot->store;
        if (is_null($store)) abort(404, 'Auction not found');
        if ($store->status == Status::DELETED->value) abort(404, 'Auction not found');

        // Get current_bid place
        $currentBid = $auctionLot->getCurrentBidPrice();
        $isBidPlaced = $auctionLot->is_bid_placed;

        if (in_array($bidType, ['MAX', 'DIRECT'])) {
            if ($auctionLot->status == Status::ARCHIVED->value) abort(404, 'AuctionLot has been archived');
            if ($store->status == Status::ARCHIVED->value) abort(404, 'Auction has been found');

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
            if ($auctionLot->status == Status::ACTIVE->value) abort(403, 'Auction Lot is now active, no longer accept any ADVANCED bids');

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
            'type' => $bidType
        ]);

        // Update current_bid
        if (in_array($bidType, ['MAX', 'DIRECT'])) {
            // Extend AuctionLot endDateTime
            $currentLotEndDateTime = Carbon::parse($auctionLot->end_datetime);

            $addExtendDays = $auctionLot->auction_time_settings['extension']['days'];
            $addExtendHours = $auctionLot->auction_time_settings['extension']['hours'];
            $addExtendMins = $auctionLot->auction_time_settings['extension']['mins'];

            $extendLotDeadline = $currentLotEndDateTime->copy()
                ->subDays($addExtendDays)
                ->subHours($addExtendHours)
                ->subMinutes($addExtendMins);

            $newLotEndDateTime = $currentLotEndDateTime;
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
                'end_datetime' => $newLotEndDateTime->toISOString()
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

        // Return Auction Store
        return response()->json([
            'message' => 'Created New maximum Bid successfully',
            '_id' => $bid->_id
        ], 200);
    }
}
