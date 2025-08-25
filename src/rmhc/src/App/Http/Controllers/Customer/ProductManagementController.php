<?php

namespace Starsnet\Project\Rmhc\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Enums
use App\Enums\Status;

// Models
use App\Models\Alias;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use Starsnet\Project\Paraqon\App\Models\AuctionLot;
use Starsnet\Project\Paraqon\App\Models\WatchlistItem;
use Starsnet\Project\Rmhc\App\Models\WinningCustomerHistory;

class ProductManagementController extends Controller
{
    /** @var Store $store */
    protected $store;

    public function __construct(Request $request)
    {
        $this->store = Store::find($request->route('store_id'))
            ?? Store::find(Alias::getValue($request->route('store_id')));
    }

    public function filterAuctionProductsByCategoriesV2(Request $request): Collection
    {
        // Get all product_id from all AuctionLot in this Store
        $productIDs = AuctionLot::where('store_id', $this->store->id)
            ->statuses([Status::ACTIVE->value, Status::ARCHIVED->value])
            ->pluck('product_id')
            ->all();

        // Get all product_id assigned to category_ids[] input from Request
        $categoryProductIDs = [];

        $categoryIDs = array_filter(array_unique((array) $request->category_ids));
        if (count($categoryIDs) > 0) {
            $categoryProductIDs = Category::whereIn('_id', $categoryIDs)
                ->pluck('item_ids')
                ->flatten()
                ->filter(fn($id) => !is_null($id))
                ->unique()
                ->values()
                ->all();

            // Override $productIDs only, with array intersection
            $productIDs = array_intersect($productIDs, $categoryProductIDs);
        }

        // Get Product(s)
        /** @var Collection $products */
        $products = Product::whereIn('_id', $productIDs)
            ->statuses([Status::ACTIVE->value, Status::ARCHIVED->value])
            ->get();

        // Get AuctionLot(s)
        /** @var Collection $auctionLots */
        $auctionLots = AuctionLot::whereIn('product_id', $productIDs)
            ->where('store_id', $this->store->id)
            ->with(['watchlistItems'])
            ->get()
            ->map(function ($lot) {
                $lot->watchlist_item_count = $lot->watchlistItems->count();
                unset($lot->watchlistItems);
                return $lot;
            });

        // Get WatchlistItem 
        $watchingAuctionIDs = WatchlistItem::where('customer_id', $this->customer()->id)
            ->where('item_type', 'auction-lot')
            ->pluck('item_id')
            ->all();

        // Get winning_customer_ids
        $latestHistories = WinningCustomerHistory::query()
            ->whereIn('auction_lot_id', $auctionLots->pluck('id')->all())
            ->orderByDesc('created_at')
            ->get()
            ->unique('auction_lot_id')
            ->keyBy('auction_lot_id')
            ->toArray();

        // Map auctionLots
        $auctionLots = $auctionLots->keyBy('product_id');

        $products = $products->map(
            function ($product)
            use ($auctionLots, $watchingAuctionIDs, $latestHistories) {
                $auctionLot = $auctionLots[$product->_id];

                // Safely extract nested values with null checks
                $bidSettings = $auctionLot->bid_incremental_settings ?? [];
                $estimatePrice = $bidSettings['estimate_price'] ?? [];

                // winning customer ids
                $winnerHistory = $latestHistories[$auctionLot->id] ?? null;
                $winningCustomerIDs = collect($winnerHistory['winning_customers'] ?? [])
                    ->pluck('customer_id')
                    ->filter()
                    ->values()
                    ->toArray();

                $product->fill([
                    'auction_lot_id' => $auctionLot->_id,
                    'current_bid' => $auctionLot->current_bid,
                    'is_reserve_price_met' => $auctionLot->current_bid >= $auctionLot->reserve_price,
                    'title' => $auctionLot->title,
                    'short_description' => $auctionLot->short_description,
                    'long_description' => $auctionLot->long_description,
                    'bid_incremental_settings' => $bidSettings,
                    'start_datetime' => $auctionLot->start_datetime,
                    'end_datetime' => $auctionLot->end_datetime,
                    'lot_number' => $auctionLot->lot_number,
                    'sold_price' => $auctionLot->sold_price,
                    'commission' => $auctionLot->commission,
                    'max_estimated_price' => $estimatePrice['max'] ?? 0,
                    'min_estimated_price' => $estimatePrice['min'] ?? 0,
                    'auction_lots' => [$auctionLot],
                    'starting_price' => $auctionLot->starting_price,
                    'is_bid_placed' => $auctionLot->is_bid_placed,
                    'watchlist_item_count' => $auctionLot->watchlist_item_count,
                    'is_watching' => in_array($auctionLot->_id, $watchingAuctionIDs, true),
                    'store' => $this->store,
                    'winning_bid_customer_ids' => $winningCustomerIDs
                ]);

                unset($product->reserve_price);

                return $product;
            }
        );

        return $products;
    }
}
