<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Enums
use App\Enums\Status;

// Models
use App\Models\Product;
use App\Models\Order;
use Starsnet\Project\Paraqon\App\Models\AuctionLot;

class ProductController extends Controller
{
    public function getAllConsignmentProducts(): array
    {
        $customer = $this->customer();
        if (!$customer) abort(404, 'Customer not found');
        $customerId = $customer->id;

        // Step 1: Find all auction lots where owned_by_customer_id matches customer's id
        $auctionLots = AuctionLot::where('owned_by_customer_id', $customerId)->get();

        // Step 2: Pluck all unique product_id from auction lots, then get Products with variants
        $productIds = $auctionLots->pluck('product_id')->unique()->filter()->values()->all();

        $products = collect();
        if (!empty($productIds)) {
            $products = Product::whereIn('id', $productIds)->with('variants')->get();
        }

        // Step 3: Find all orders where cart_items array contains any product_id from Step 2
        $orders = collect();
        if (!empty($productIds)) {
            $orders = Order::raw(function ($collection) use ($productIds) {
                $pipeline = [
                    [
                        '$match' => [
                            'cart_items.product_id' => ['$in' => $productIds]
                        ]
                    ]
                ];
                return $collection->aggregate($pipeline, ['allowDiskUse' => true]);
            });
            $orders = collect($orders);
        }

        return [
            'products' => $products,
            'auction_lots' => $auctionLots,
            'orders' => $orders
        ];
    }

    public function getAllOwnedProducts(): Collection
    {
        $products = Product::statusActive()
            ->where('owned_by_customer_id', $this->customer()->id)
            ->whereIn('listing_status', ["AVAILABLE", "PENDING_FOR_AUCTION"])
            ->get();

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
