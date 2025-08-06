<?php

namespace Starsnet\Project\WhiskyWhiskers\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// Models
use App\Models\Product;
use Starsnet\Project\WhiskyWhiskers\App\Models\AuctionLot;
use Starsnet\Project\WhiskyWhiskers\App\Models\Bid;
use Starsnet\Project\WhiskyWhiskers\App\Models\PassedAuctionRecord;

class CustomerController extends Controller
{
    public function getAllOwnedProducts(Request $request)
    {
        $customerId = $request->route('customer_id');

        $products = Product::statusActive()
            ->where('owned_by_customer_id', $customerId)
            ->get();

        foreach ($products as $product) {
            $product->product_variant_id = optional($product->variants()->latest()->first())->_id;

            $passedAuctionCount = PassedAuctionRecord::where(
                'customer_id',
                $customerId
            )->where(
                'product_id',
                $product->_id
            )->count();
            $product->passed_auction_count = $passedAuctionCount;
        }

        return $products;
    }

    public function getAllOwnedAuctionLots(Request $request)
    {
        $auctionLots = AuctionLot::where('owned_by_customer_id', $request->route('customer_id'))
            ->with([
                'product',
                'productVariant',
                'store',
                'latestBidCustomer',
                'winningBidCustomer'
            ])
            ->get();

        foreach ($auctionLots as $auctionLot) {
            $auctionLot->current_bid = $auctionLot->getCurrentBidPrice();
        }

        return $auctionLots;
    }

    public function getAllBids(Request $request)
    {
        $products = Bid::where('customer_id', $request->route('customer_id'))
            ->where('is_hidden', false)
            ->with([
                'product',
                'productVariant',
                'store',
            ])
            ->get();

        return $products;
    }

    public function hideBid(Request $request)
    {
        Bid::where('_id', $request->route('bid_id'))->update(['is_hidden' => true]);

        return [
            'message' => 'Bid updated is_hidden as true'
        ];
    }
}
