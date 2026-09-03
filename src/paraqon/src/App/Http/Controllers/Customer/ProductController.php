<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

// Enums
use App\Enums\Status;

// Models
use App\Models\Product;
use App\Models\Order;
use App\Models\Store;
use Starsnet\Project\Paraqon\App\Models\AuctionLot;

class ProductController extends Controller
{
    public function getAllOwnedProducts(Request $request): Collection
    {
        $customerID = $this->customer()->id;

        $query = Product::statusActive()
            ->where(function ($query) use ($customerID) {
                $query->where('seller_id', $customerID)
                    ->orWhere(function ($legacyQuery) use ($customerID) {
                        $legacyQuery->whereNull('seller_id')
                            ->where('owned_by_customer_id', $customerID);
                    });
            });

        if (!$request->boolean('include_all_active')) {
            $query->whereIn('listing_status', ["AVAILABLE", "PENDING_FOR_AUCTION"]);
        }

        $products = $query->get();

        foreach ($products as $product) {
            $product->product_variant_id = optional($product->variants()->latest()->first())->_id;
            $product->passed_auction_count = 0;
        }

        return $products;
    }

    public function updateListingStatuses(Request $request): array
    {
        foreach ($request->items as $item) {
            $product = Product::find($item['product_id']);
            if (is_null($product)) continue;

            $attributes = ['listing_status' => $item['listing_status']];
            $product->update($attributes);
        }

        return ['message' => 'Updated ' . count($request->items) . ' Product(s) listing_status successfully.'];
    }

    public function getProductDetails(Request $request): Product
    {
        $product = Product::find($request->route('product_id'));
        if (is_null($product)) abort(404, 'Product not found');
        if ($product->status !== Status::ACTIVE->value) abort(404, 'Product is not available for public');
        return $product;
    }
}
