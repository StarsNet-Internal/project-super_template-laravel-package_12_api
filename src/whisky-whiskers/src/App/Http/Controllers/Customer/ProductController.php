<?php

namespace Starsnet\Project\WhiskyWhiskers\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Enums
use App\Enums\Status;

// Models
use App\Models\Product;
use Starsnet\Project\WhiskyWhiskers\App\Models\PassedAuctionRecord;

class ProductController extends Controller
{
    public function getAllOwnedProducts(Request $request): Collection
    {
        $customer = $this->customer();

        $products = Product::statusActive()
            ->where('owned_by_customer_id', $customer->_id)
            ->whereIn('listing_status', ["AVAILABLE", "PENDING_FOR_AUCTION"])
            ->get();

        foreach ($products as $product) {
            $product->product_variant_id = optional($product->variants()->latest()->first())->_id;
            $product->passed_auction_count = PassedAuctionRecord::where('customer_id', $customer->id)
                ->where('product_id', $product->_id)
                ->count();
        }
        return $products;
    }

    public function getProductDetails(Request $request): Product
    {
        /** @var ?Product $product */
        $product = Product::find($request->route('product_id'));
        if (is_null($product)) abort(404, 'Product not found');
        if ($product->status !== Status::ACTIVE->value) abort(404, 'Product is not available for public');

        return $product;
    }
}
