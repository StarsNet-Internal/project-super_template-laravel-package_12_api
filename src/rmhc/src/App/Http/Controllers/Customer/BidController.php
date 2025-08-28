<?php

namespace Starsnet\Project\Rmhc\App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Support\Collection;

use Starsnet\Project\Paraqon\App\Models\Bid;
use Starsnet\Project\Rmhc\App\Models\WinningCustomerHistory;

class BidController extends Controller
{
    public function getAllBids(): Collection
    {
        $bids = Bid::where('customer_id', $this->customer()->id)
            ->where('is_hidden', false)
            ->with(['store', 'product', 'auctionLot'])
            ->get();
        $auctionLotIDs = $bids->pluck('auction_lot_id')->all();

        // Get winning_customers
        $latestHistories = WinningCustomerHistory::query()
            ->whereIn('auction_lot_id', $auctionLotIDs)
            ->orderByDesc('created_at')
            ->get()
            ->unique('auction_lot_id')
            ->keyBy('auction_lot_id');

        foreach ($bids as $bid) {
            $winnerHistory = $latestHistories[$bid->auction_lot_id] ?? null;
            $bid->winning_customers = $winnerHistory?->winning_customers ?? [];
        }

        return $bids;
    }
}
