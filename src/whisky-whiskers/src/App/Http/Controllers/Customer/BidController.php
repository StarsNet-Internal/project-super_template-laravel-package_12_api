<?php

namespace Starsnet\Project\WhiskyWhiskers\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Models
use Starsnet\Project\WhiskyWhiskers\App\Models\AuctionLot;
use Starsnet\Project\WhiskyWhiskers\App\Models\Bid;

class BidController extends Controller
{
    public function getAllBids(): Collection
    {
        $bids = Bid::where('customer_id', $this->customer()->id)
            ->with([
                'store' => function ($query) {
                    $query->select('title', 'images', 'start_datetime', 'end_datetime');
                },
                'product' => function ($query) {
                    $query->select('title', 'images');
                },
            ])
            ->get();

        // Correct the bid value of highest bid to the lowest increment possible
        foreach ($bids as $bid) {
            $auctionLotID = $bid->auction_lot_id;
            $auctionLot = AuctionLot::find($auctionLotID);
            $bid->auction_lot = [
                '_id' => $bid->auction_lot_id,
                'starting_price' => $auctionLot->starting_price,
                'current_bid' => $auctionLot->getCurrentBidPrice(),
            ];
        }

        return $bids;
    }
}
