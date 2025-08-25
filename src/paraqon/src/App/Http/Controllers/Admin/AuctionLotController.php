<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Carbon\Carbon;

// Enums
use App\Enums\Status;

// Models
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Store;
use Starsnet\Project\Paraqon\App\Models\AuctionLot;
use Starsnet\Project\Paraqon\App\Models\Bid;
use Starsnet\Project\Paraqon\App\Models\BidHistory;
use Starsnet\Project\Paraqon\App\Models\LiveBiddingEvent;

class AuctionLotController extends Controller
{
    public function createAuctionLot(Request $request): array
    {
        /** @var ?Customer $customer */
        if (is_string($request->customer_id)) {
            $customer = Customer::find($request->customer_id);
            if (is_null($customer)) abort(404, 'Customer not found');
        }

        /** @var ?Product $product */
        $product = Product::find($request->product_id);
        if (is_null($product)) abort(404, 'Product not found');

        // Get Store
        /** @var ?Store $store */
        $store = Store::find($request->store_id);
        if (is_null($store)) abort(404, 'Store not found');

        $highestLotNumber = AuctionLot::where('store_id', $request->store_id)
            ->get()
            ->max('lot_number')
            ?? 0;
        $lotNumber = $highestLotNumber + 1;

        // Create AuctionLot
        $auctionLotAttributes = [
            'title' => $request->title,
            'short_description' => $request->short_description,
            'long_description' => $request->long_description,

            'product_id' => $request->product_id,
            'product_variant_id' => $request->product_variant_id,
            'store_id' => $request->store_id,
            'owned_by_customer_id' => $request->customer_id,

            'starting_price' => $request->starting_price ?? 0,
            'current_bid' => $request->starting_price ?? 0,
            'reserve_price' => $request->reserve_price ?? 0,

            'auction_time_settings' => $request->auction_time_settings,
            'bid_incremental_settings' => $request->bid_incremental_settings,

            'start_datetime' => $request->start_datetime,
            'end_datetime' => $request->end_datetime,

            'status' => $request->status,

            'documents' => $request->documents ?? [],
            'attributes' => $request->attributes ?? [],
            'shipping_costs' => $request->shipping_costs ?? [],

            'lot_number' => $lotNumber,

            'brand' => $request->brand,
            'saleroom_notice' => $request->saleroom_notice,

            'commission_rate' => $request->commission_rate
        ];
        $auctionLot = AuctionLot::create($auctionLotAttributes);

        // Create BidHistory
        BidHistory::create([
            'auction_lot_id' => $auctionLot->id,
            'current_bid' => $auctionLot->starting_price,
            'histories' => []
        ]);

        return [
            'message' => 'Created AuctionLot successfully',
            '_id' => $auctionLot->id,
        ];
    }

    public function updateAuctionLotDetails(Request $request): array
    {
        /** @var ?AuctionLot $auctionLot */
        $auctionLot = AuctionLot::find($request->route('id'));
        if (is_null($auctionLot)) abort(404, 'AuctionLot not found');

        $auctionLot->update($request->all());

        return ['message' => 'Updated AuctionLot successfully'];
    }

    public function deleteAuctionLots(Request $request): array
    {
        $updatedCount = AuctionLot::whereIn('_id', (array) $request->input('ids'))
            ->update([
                'status' => Status::DELETED->value,
                'deleted_at' => now()
            ]);

        return ['message' => 'Deleted ' . $updatedCount . ' Lot(s) successfully'];
    }

    public function getAllAuctionLots(Request $request): Collection
    {
        // Extract attributes from $request
        $categoryID = $request->category_id;
        $storeID = $request->store_id;

        // Get AuctionLots
        $auctionLots = new Collection();

        if (!is_null($storeID)) {
            /** @var Collection $auctionLots */
            $auctionLots = AuctionLot::with(['bids'])
                ->where('store_id', $storeID)
                ->where('status', '!=', Status::DELETED->value)
                ->whereNotNull('lot_number')
                ->get();
        } else if (!is_null($categoryID)) {
            $storeID = Category::find($categoryID)->model_type_id;

            /** @var Collection $auctionLots */
            $auctionLots = AuctionLot::with(['bids'])
                ->whereHas('product', function ($query) use ($categoryID) {
                    $query->whereHas('categories', function ($query2) use ($categoryID) {
                        $query2->where('_id', $categoryID);
                    });
                })
                ->where('store_id', $storeID)
                ->where('status', '!=', Status::DELETED->value)
                ->whereNotNull('lot_number')
                ->get();
        } else {
            /** @var Collection $auctionLots */
            $auctionLots = AuctionLot::with(['bids'])
                ->where('status', '!=', Status::DELETED->value)
                ->whereNotNull('lot_number')
                ->get();
        }

        // Get Bids statistics
        foreach ($auctionLots as $lot) {
            $lot->bid_count = $lot->bids->count();
            $lot->participated_user_count = $lot->bids
                ->pluck('customer_id')
                ->unique()
                ->count();

            $lot->last_bid_placed_at = optional(
                $lot->bids
                    ->sortByDesc('created_at')
                    ->first()
            )
                ->created_at;

            unset($lot->bids);
        }

        return $auctionLots;
    }

    public function getAuctionLotDetails(Request $request): AuctionLot
    {
        /** @var ?AuctionLot $auctionLot */
        $auctionLot = AuctionLot::find($request->route('id'));
        if (!$auctionLot) abort(404, 'AuctionLot not found');
        if ($auctionLot->status === Status::DELETED->value) abort(404, 'Auction Lot not found');

        return $auctionLot;
    }

    public function getAllAuctionLotBids(Request $request): Collection
    {
        /** @var ?AuctionLot $auctionLot */
        $auctionLot = AuctionLot::find($request->route('id'));
        if (!$auctionLot) abort(404, 'AuctionLot not found');
        if ($auctionLot->status === Status::DELETED->value) abort(404, 'AuctionLot not found');

        return $auctionLot->bids()->with('customer')->get();
    }

    public function massUpdateAuctionLots(Request $request): array
    {
        $lotAttributes = $request->lots;

        foreach ($lotAttributes as $lot) {
            /** @var ?AuctionLot $auctionLot */
            $auctionLot = AuctionLot::find($lot['id']);

            // Check if the AuctionLot exists
            if (!is_null($auctionLot)) {
                $updateAttributes = $lot;
                unset($updateAttributes['id']);
                $auctionLot->update($updateAttributes);
            }
        }

        return ['message' => 'Auction Lots updated successfully'];
    }

    public function createLiveBid(Request $request)
    {
        $now = now();

        // Extract attributes from $request
        $auctionLotId = $request->route('auction_lot_id');
        $requestedBid = $request->bid;
        $bidType = $request->input('type', 'MAX');
        $customer = Customer::find($request->customer_id) ?? $this->customer();

        // Validation for the request body
        if (!in_array($bidType, ['MAX', 'DIRECT', 'ADVANCED'])) abort(400, 'Invalid type');

        // Check AuctionLot
        /** @var ?AuctionLot $auctionLot */
        $auctionLot = AuctionLot::find($auctionLotId);
        if (is_null($auctionLot)) abort(404, 'AuctionLot not found');
        if ($auctionLot->is_disabled == true) abort(404, 'AuctionLot not found');
        if ($auctionLot->status == Status::DELETED->value) abort(404, 'AuctionLot not found');
        if ($auctionLot->owned_by_customer_id == $customer->id) abort(404, 'You cannot place bid on your own auction lot');

        // Check Store/Auction
        /** @var ?Store $store */
        $store = $auctionLot->store;
        if (is_null($store)) abort(404, 'Auction not found');
        if ($store->status == Status::DELETED->value) abort(404, 'Auction not found');

        // Get current_bid place
        $currentBid = $auctionLot->getCurrentBidPrice();

        if (in_array($bidType, ['MAX', 'DIRECT'])) {
            if ($auctionLot->status == Status::ARCHIVED->value) abort(404, 'AuctionLot has been archived');
            if ($store->status == Status::ARCHIVED->value) abort(404, 'Auction has been found');

            $highestAdvancedBid = $auctionLot->bids()
                ->where('is_hidden', false)
                ->where('type', 'ADVANCED')
                ->orderByDesc('bid')
                ->first();
            $highestAdvancedBidValue = optional($highestAdvancedBid)->bid ?? 0;

            if ($requestedBid < $currentBid && $requestedBid < $highestAdvancedBidValue) {
                return response()->json([
                    'message' => 'Your bid cannot be lower than highest advanced bid value of ' . $highestAdvancedBidValue . '.',
                    'error_status' => 1,
                    'bid' => $highestAdvancedBidValue
                ], 400);
            }

            $auctionLot->bids()
                ->where('is_hidden', false)
                ->where('type', 'DIRECT')
                ->where('bid', '>=', $requestedBid)
                ->update(['is_hidden' => true]);
        }

        // Hide previous placed ADVANCED bid, if there's any
        if ($bidType == 'ADVANCED') {
            if ($auctionLot->status == Status::ACTIVE->value) abort(400, 'Auction Lot is now active, no longer accept any ADVANCED bids');

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
                ->where('is_hidden', false)
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
            // if ($isBidPlaced == false || $newCurrentBid > $currentBid) {
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

        return [
            'message' => 'Created New maximum Bid successfully',
            '_id' => $bid->id
        ];
    }

    public function resetAuctionLot(Request $request): array
    {
        /** @var AuctionLot $auctionLot */
        $auctionLot = AuctionLot::find($request->route('auction_lot_id'));
        if (is_null($auctionLot)) abort(404, 'AuctionLot not found');

        /** @var BidHistory $bidHistory */
        $bidHistory = BidHistory::firstWhere('auction_lot_id', $request->route('auction_lot_id'));
        if (is_null($bidHistory)) abort(404, 'BidHistory not found');

        // Reset events
        LiveBiddingEvent::where('store_id', $auctionLot->store_id)
            ->where('value_1', $request->route('auction_lot_id'))
            ->update(['is_hidden' => true]);

        // Hide previous DIRECT bids
        $auctionLot->bids()
            ->where('is_hidden', false)
            ->where('type', 'DIRECT')
            ->update(['is_hidden' => true]);

        // Reset history
        $bidHistory->update(['current_bid' => $auctionLot->starting_price]);
        foreach ($bidHistory['histories'] as $history) {
            $history->update(['is_hidden' => true]);
        }

        // Get current bid and winner
        $auctionLotMaximumBid = $auctionLot->bids()
            ->where('is_hidden',  false)
            ->orderBy('bid', 'desc')
            ->first();

        // Copy from Customer BidController cancelBid START
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
            $bidHistory->histories()->create([
                'winning_bid_customer_id' => $winningCustomerID,
                'current_bid' => $newCurrentBid
            ]);
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

        return ['message' => 'Lot reset successfully'];
    }

    public function updateBidHistoryLastItem(Request $request): array
    {
        /** @var AuctionLot $auctionLot */
        $auctionLot = AuctionLot::find($request->route('auction_lot_id'));
        if (is_null($auctionLot)) abort(404, 'AuctionLot not found');

        /** @var Customer $customer */
        $customer = Customer::find($request->winning_bid_customer_id);
        if (is_null($customer)) abort(404, 'Customer not found');

        // Update BidHistory
        $bidHistory = $auctionLot->bidHistory()->first();
        if (is_null($bidHistory)) abort(404, 'BidHistory not found');
        if ($bidHistory->histories()->count() == 0) abort(404, 'BidHistory histories is empty');

        $lastItem = $bidHistory->histories()->last();
        $lastItem->update(['winning_bid_customer_id' => $request->winning_bid_customer_id]);

        return ['message' => 'Updated BidHistory winning_bid_customer_id for the last item'];
    }
}
