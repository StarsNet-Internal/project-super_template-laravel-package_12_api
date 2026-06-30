<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Carbon\Carbon;

// Enums
use App\Enums\Status;
use App\Enums\StoreType;

// Models
use App\Models\Store;
use Starsnet\Project\Paraqon\App\Http\Controllers\Concerns\CachesEndedAuctionData;
use Starsnet\Project\Paraqon\App\Models\AuctionLot;
use Starsnet\Project\Paraqon\App\Models\Bid;
use Starsnet\Project\Paraqon\App\Models\BidHistory;

class BidController extends Controller
{
    use CachesEndedAuctionData;

    public function getAllBids(Request $request): Collection
    {
        $customerId = (string) $this->customer()->id;

        // Split auction Stores into ended vs active. Bids in an ended auction are
        // final, so each (customer, store) is cached under a stable per-store key
        // — no changing hash, an immutable value that can't be corrupted by
        // concurrent writers. Active-auction bids stay live.
        [$endedStores, $activeStores] = Store::where('type', StoreType::OFFLINE->value)
            ->get(['_id', 'end_datetime'])
            ->partition(fn(Store $store) => $this->isStoreEnded($store));

        $endedBids = $endedStores
            ->flatMap(fn(Store $store) => $this->endedBidsForStore($customerId, (string) $store->_id));

        $activeBids = $this->activeBidsForStores(
            $customerId,
            $activeStores->map(fn(Store $store) => (string) $store->_id)->all()
        );

        // Combine, apply the optional datetime filters, and order newest-first.
        $bids = $this->applyBidFilters($endedBids->concat($activeBids), $request);

        return $bids->sortByDesc('created_at')->values();
    }

    /**
     * A customer's bids in one ended auction store: final data, cached forever
     * under a stable per-store key. Reused across requests.
     */
    private function endedBidsForStore(string $customerId, string $storeId): Collection
    {
        $key = $this->mongoCacheKey(
            'customer_bids:ended:customer_' . $customerId . ':store:' . $storeId
        );

        return $this->rememberChunkedForever($key, function () use ($customerId, $storeId) {
            return $this->buildBidsQuery($customerId)
                ->where('store_id', $storeId)
                ->get();
        });
    }

    /** Live (uncached) bids for the customer across the given stores. */
    private function activeBidsForStores(string $customerId, array $storeIds): Collection
    {
        if (empty($storeIds)) {
            return new Collection();
        }

        return $this->buildBidsQuery($customerId)
            ->whereIn('store_id', $storeIds)
            ->get();
    }

    private function buildBidsQuery(string $customerId)
    {
        return Bid::where('customer_id', $customerId)
            ->where('is_hidden', false)
            ->with(['store', 'product', 'auctionLot']);
    }

    private function applyBidFilters(Collection $bids, Request $request): Collection
    {
        if ($request->has('start_datetime') && $request->start_datetime) {
            $start = Carbon::parse($request->start_datetime);
            $bids = $bids->filter(fn($bid) => Carbon::parse($bid->created_at)->gte($start));
        }

        if ($request->has('end_datetime') && $request->end_datetime) {
            $end = Carbon::parse($request->end_datetime);
            $bids = $bids->filter(fn($bid) => Carbon::parse($bid->created_at)->lte($end));
        }

        return $bids->values();
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
