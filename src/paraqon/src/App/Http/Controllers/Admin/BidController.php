<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

// Enums
use App\Enums\Status;
use App\Enums\ReplyStatus;

// Models
use App\Models\Customer;
use Illuminate\Support\Collection;
use Starsnet\Project\Paraqon\App\Models\AuctionLot;
use Starsnet\Project\Paraqon\App\Models\AuctionRegistrationRequest;
use Starsnet\Project\Paraqon\App\Models\Bid;
use Starsnet\Project\Paraqon\App\Models\BidHistory;

class BidController extends Controller
{
    public function createOnlineBidByCustomer(Request $request): JsonResponse
    {
        $now = now();

        // Extract attributes from $request
        $auctionLotID = $request->route('auction_lot_id');
        $requestedBid = $request->bid;
        $bidType = $request->input('type', 'MAX');

        // Validation for the request body
        if (!in_array($bidType, ['MAX', 'DIRECT', 'ADVANCED'])) abort(400, 'Invalid type');

        /** @var ?Customer $customer */
        $customer = Customer::find($request->customer_id) ?? $this->customer();
        if (is_null($customer)) abort(404, 'Customer not found');

        // Check AuctionLot
        /** @var ?AuctionLot $auctionLot */
        $auctionLot = AuctionLot::find($auctionLotID);
        if (is_null($auctionLot)) abort(404, 'AuctionLot not found');
        if ($auctionLot->status == Status::DELETED->value) abort(404, 'AuctionLot not found');
        if ($auctionLot->owned_by_customer_id == $customer->id) abort(404, 'You cannot place bid on your own auction lot');

        // Check AuctionRegistrationRequest
        /** @var ?AuctionRegistrationRequest $auctionRegistrationRequest */
        $auctionRegistrationRequest = AuctionRegistrationRequest::where('requested_by_customer_id', $customer->id)
            ->where('status', Status::ACTIVE->value)
            ->where('reply_status', ReplyStatus::APPROVED->value)
            ->latest()
            ->first();
        if (is_null($auctionRegistrationRequest)) abort(404, 'ACTIVE and APPROVED AuctionRegistrationRequest not found for this customer');

        /** @var ?Store $store */
        $store = $auctionLot->store;
        if (is_null($store)) abort(404, 'Store not found');
        if ($store->status == Status::DELETED->value) abort(404, 'Store not found');

        // Get current_bid place
        $currentBid = $auctionLot->getCurrentBidPrice();
        $isBidPlaced = $auctionLot->is_bid_placed;

        if (in_array($bidType, ['MAX', 'DIRECT'])) {
            if ($auctionLot->status == Status::ARCHIVED->value) abort(404, 'Auction Lot has been archived');
            if ($store->status == Status::ARCHIVED->value) abort(404, 'Auction has been archived');

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
            /** @var Bid $userExistingMaximumBid */
            $userExistingMaximumBid = Bid::where('auction_lot_id', $auctionLotID)
                ->where('customer_id', $customer->id)
                ->where('is_hidden',  false)
                ->where('type', $bidType)
                ->orderBy('bid', 'desc')
                ->first();

            // Determine minimum possible bid for Customer
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
            if ($auctionLot->status == Status::ACTIVE->value) abort(403, 'AuctionLot is now status ACTIVE, no longer accept any ADVANCED bids');

            // Check if this MAX or DIRECT bid place after start_datetime
            if ($now >= Carbon::parse($store->start_datetime)) {
                return response()->json([
                    'message' => 'Auction has started, no longer accept any ADVANCED bids',
                    'error_status' => 4,
                    'system_time' => $now,
                    'auction_start_datetime' => Carbon::parse($store->start_datetime)
                ], 400);
            }

            // Hide previously placed ADVANCED bid
            Bid::where('auction_lot_id', $auctionLotID)
                ->where('customer_id', $customer->_id)
                ->where('is_hidden', false)
                ->update(['is_hidden' => true]);
        }

        // Create Bid
        $bid = Bid::create([
            'auction_lot_id' => $auctionLotID,
            'customer_id' => $customer->_id,
            'store_id' => $auctionLot->store_id,
            'product_id' => $auctionLot->product_id,
            'product_variant_id' => $auctionLot->product_variant_id,
            'bid' => $requestedBid,
            'type' => $bidType
        ]);

        // Update current_bid
        if (in_array($bidType, ['MAX', 'DIRECT'])) {
            // Update current_bid
            // Find winningCustomerID
            $auctionLotMaximumBid = Bid::where('auction_lot_id', $auctionLotID)
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


            if ($isBidPlaced == false || $newCurrentBid > $currentBid) {
                // Create Bid History Record
                $bidHistory = BidHistory::where('auction_lot_id', $auctionLotID)->first();
                if ($bidHistory == null) {
                    $bidHistory = BidHistory::create([
                        'auction_lot_id' => $auctionLotID,
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

                // Extend Store endDateTime
                $currentStoreEndDateTime = Carbon::parse($store->end_datetime);
                if ($newLotEndDateTime > $currentStoreEndDateTime) {
                    $store->update([
                        'end_datetime' => $newLotEndDateTime->toISOString()
                    ]);
                }
            }
        }

        if ($bidType == 'ADVANCED') {
            $bidHistory = BidHistory::where('auction_lot_id', $auctionLotID)->first();
            if ($bidHistory == null) {
                $bidHistory = BidHistory::create([
                    'auction_lot_id' => $auctionLotID,
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
            $auctionLotMaximumBid = Bid::where('auction_lot_id', $auctionLotID)
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

        return response()->json([
            'message' => 'Created New maximum Bid successfully',
            '_id' => $bid->id
        ], 200);
    }

    public function cancelBidByCustomer(Request $request): array
    {
        $now = now();

        /** @var ?Bid $bid */
        $bid = Bid::find($request->route('bid_id'));
        if (is_null($bid)) abort(404, 'Bid not found');

        /** @var ?AuctionLot $auctionLot */
        $auctionLot = $bid->auctionLot;
        if (is_null($auctionLot)) abort(404, 'AuctionLot not found');
        if ($auctionLot->status == Status::DELETED) abort(404, 'AuctionLot not found');
        if ($auctionLot->status == Status::ACTIVE) abort(400, 'You cannot cancel ADVANCED bid when the auction lot is already ACTIVE');
        if ($now >= Carbon::parse($auctionLot->start_datetime))  abort(400, 'You cannot cancel ADVANCED bid when the auction lot has already started');

        // Update Bid
        $bid->update(['is_hidden' => true]);

        // Update BidHistory and AuctionLot
        if ($bid->type == 'ADVANCED') {
            $auctionLotID = $auctionLot->_id;

            /** @var ?BidHistory $bidHistory */
            $bidHistory = BidHistory::where('auction_lot_id', $auctionLotID)->first();

            if (is_null($bidHistory)) {
                /** @var BidHistory $bidHistory */
                $bidHistory = BidHistory::create([
                    'auction_lot_id' => $auctionLotID,
                    'current_bid' => $auctionLot->starting_price,
                    'histories' => []
                ]);
            } else {
                // Clear all histories items
                $bidHistory->update([
                    'current_bid' => $auctionLot->starting_price,
                    'histories' => []
                ]);
            }

            // Find winningCustomerID
            /** @var Bid $auctionLotMaximumBid */
            $auctionLotMaximumBid = Bid::where('auction_lot_id', $auctionLotID)
                ->where('is_hidden',  false)
                ->orderBy('bid', 'desc')
                ->first();

            if (!is_null($auctionLotMaximumBid)) {
                // get current bid and winner
                $newCurrentBid = $auctionLot->getCurrentBidPrice(
                    true,
                    $auctionLotMaximumBid->customer_id,
                    $auctionLotMaximumBid->bid,
                    $auctionLotMaximumBid->type
                );

                $winningCustomerID = !is_null($auctionLotMaximumBid)
                    ? $auctionLotMaximumBid->customer_id
                    : null;

                // Update BidHistory
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
            } else {
                $auctionLot->update([
                    'is_bid_placed' => false,
                    'current_bid' => $auctionLot->starting_price,
                    'latest_bid_customer_id' => null,
                    'winning_bid_customer_id' => null,
                ]);
            }
        }

        return ['message' => 'Bid cancelled successfully'];
    }

    public function getCustomerAllBids(Request $request): Collection
    {
        return Bid::where('customer_id', $request->route('customer_id'))
            ->with([
                'product',
                'productVariant',
                'store',
            ])
            ->get();
    }

    public function hideBid(Request $request): array
    {
        Bid::where('id', $request->route('bid_id'))->update(['is_hidden' => true]);

        return [
            'message' => 'Bid updated is_hidden as true'
        ];
    }
}
