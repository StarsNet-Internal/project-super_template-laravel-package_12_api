<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Carbon\Carbon;

// Enums
use App\Enums\Status;
use COM;
// Models
use Starsnet\Project\Paraqon\App\Models\AuctionLot;
use Starsnet\Project\Paraqon\App\Models\Bid;
use Starsnet\Project\Paraqon\App\Models\BidHistory;

class BidController extends Controller
{
    public function getAllBids(Request $request): Collection
    {
        $query = Bid::where('customer_id', $this->customer()->id)
            ->where('is_hidden', false)
            ->with(['store', 'product', 'auctionLot']);

        if ($request->has('start_datetime') && $request->start_datetime) {
            $query->where('created_at', '>=', Carbon::parse($request->start_datetime));
        }

        if ($request->has('end_datetime') && $request->end_datetime) {
            $query->where('created_at', '<=', Carbon::parse($request->end_datetime));
        }

        return $query->get();
    }

    public function cancelBid(Request $request): array
    {
        $now = now();

        /** @var ?Bid $bid */
        $bid = Bid::find($request->route('id'));
        if (is_null($bid)) abort(404, 'Bid not found');

        $customer = $this->customer();
        if ($bid->customer_id != $customer->_id) abort(400, 'You cannot cancel bids that are not placed by your account');

        /** @var ?AuctionLot $auctionLot */
        $auctionLot = $bid->auctionLot;
        if (is_null($auctionLot)) abort(404, 'AuctionLot not found');
        if ($auctionLot->status == Status::DELETED->value) abort(404, 'AuctionLot not found');
        if ($auctionLot->status == Status::ACTIVE->value) abort(404, 'You cannot cancel ADVANCED bid when the auction lot is already ACTIVE');
        if ($now >= Carbon::parse($auctionLot->start_datetime)) abort(404, 'You cannot cancel ADVANCED bid when the auction lot is already ACTIVE');

        // Update Bid
        $bid->update(['is_hidden' => true]);

        // Update BidHistory and AuctionLot
        if ($bid->type == 'ADVANCED') {
            $auctionLotID = $auctionLot->_id;
            $bidHistory = BidHistory::where('auction_lot_id', $auctionLotID)->first();
            if ($bidHistory == null) {
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
            $auctionLotMaximumBid = Bid::where('auction_lot_id', $auctionLotID)
                ->where('is_hidden', false)
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

                $winningCustomerID = null;
                if (!is_null($auctionLotMaximumBid)) {
                    $winningCustomerID = $auctionLotMaximumBid->customer_id;
                }

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

    public function cancelLiveBid(Request $request): array
    {
        /** @var ?Bid $bid */
        $bid = Bid::find($request->route('id'));
        if (is_null($bid)) abort(404, 'Bid not found');

        $customer = $this->customer();
        if ($bid->customer_id != $customer->id) abort(404, 'You cannot cancel bids that are not placed by your account');

        // Validate AuctionLot
        $auctionLot = $bid->auctionLot;
        if (is_null($auctionLot)) abort(404, 'AuctionLot not found');
        if ($auctionLot->status == Status::DELETED->value) abort(404, 'AuctionLot not found');
        if ($auctionLot->status == Status::ACTIVE->value) abort(404, 'You cannot cancel ADVANCED bid when the auction lot is already ACTIVE');

        // Update Bid
        $bid->update(['is_hidden' => true]);

        // Update BidHistory and AuctionLot
        if ($bid->type == 'ADVANCED') {
            $auctionLotID = $auctionLot->_id;

            $bidHistory = BidHistory::where('auction_lot_id', $auctionLotID)->first();
            if ($bidHistory == null) {
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
            $auctionLotMaximumBid = Bid::where('auction_lot_id', $auctionLotID)
                ->where('is_hidden', false)
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

                $winningCustomerID = null;
                if (!is_null($auctionLotMaximumBid)) {
                    $winningCustomerID = $auctionLotMaximumBid->customer_id;
                }

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
}
