<?php

namespace Starsnet\Project\Rmhc\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Enums
use App\Enums\Status;
use App\Models\Category;

// Models
use Starsnet\Project\Paraqon\App\Models\AuctionLot;
use Starsnet\Project\Rmhc\App\Models\WinningCustomerHistory;

class AuctionLotController extends Controller
{
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

        // Get winning_customer_ids
        $latestHistories = WinningCustomerHistory::query()
            ->whereIn('auction_lot_id', $auctionLots->pluck('id')->all())
            ->orderByDesc('created_at')
            ->get()
            ->unique('auction_lot_id')
            ->keyBy('auction_lot_id');

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

            // Append winning_customer_ids
            $winnerHistory = $latestHistories[$lot->_id] ?? null;
            $lot->winning_customers = $winnerHistory?->winning_customers ?? [];

            unset($lot->bids);
        }

        return $auctionLots;
    }
}
