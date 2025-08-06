<?php

namespace Starsnet\Project\WhiskyWhiskers\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Enums
use App\Enums\Status;

// Models
use App\Models\Customer;
use App\Models\Product;
use Starsnet\Project\WhiskyWhiskers\App\Models\AuctionLot;
use Starsnet\Project\WhiskyWhiskers\App\Models\BidHistory;

class AuctionLotController extends Controller
{
    public function getAllAuctionLotsInStorage()
    {
        $products = Product::where('listing_status', 'AVAILABLE')
            ->statuses(Status::defaultStatuses())
            ->get();

        $lots = AuctionLot::whereIn('product_id', $products->pluck('id'))
            ->statuses(Status::defaultStatuses())
            ->get();

        foreach ($products as $product) {
            $filteredLots = $lots->filter(function ($lot) use ($product) {
                return $lot->product_id == $product->_id;
            })->all();

            $product->auction_lots = array_values($filteredLots);
        }

        return $products;
    }

    public function getAuctionLotDetails(Request $request)
    {
        // Extract attributes from $request
        $auctionLotId = $request->route('auction_lot_id');

        $auctionLot = AuctionLot::with([
            'product',
            'productVariant',
            'store',
            'bids'
        ])->find($auctionLotId);

        // Get current_bid
        $auctionLot->current_bid = $auctionLot->getCurrentBidPrice();

        // Check is_reserve_met
        $auctionLot->is_reserve_price_met = $auctionLot->current_bid >= $auctionLot->reserve_price;

        $bidHistory = BidHistory::where('auction_lot_id', $auctionLotId)->first();
        $displayBidRecords = $bidHistory['histories'];

        $customers = Customer::with([
            'account'
        ])->find($bidHistory['histories']->pluck('winning_bid_customer_id'));

        // Attach customer and account information to each bid
        foreach ($displayBidRecords as $bid) {
            $winningBidCustomerId = $bid['winning_bid_customer_id'];
            $customer = $customers->first(function ($customer) use ($winningBidCustomerId) {
                return $customer->id == $winningBidCustomerId;
            });

            $bid->username = $customer->account->username;
            $bid->avatar = $customer->account->avatar;
        }

        $auctionLot->histories = $displayBidRecords;

        // Return Auction Store
        return $auctionLot;
    }
}
