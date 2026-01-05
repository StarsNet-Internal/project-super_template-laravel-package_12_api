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

        // Step 1: Get products where owned_by_customer_id matches customer's id
        $products = Product::where('owned_by_customer_id', $customerId)->get();

        // Get product IDs from the products collection
        $productIds = $products->pluck('id')->all();

        // Step 2: Get latest auction_lot per product_id where product_id is in the products collection
        $auctionLots = collect();
        if (!empty($productIds)) {
            $auctionLots = AuctionLot::raw(function ($collection) use ($productIds) {
                $pipeline = [
                    // Match lots where product_id is in the products collection
                    [
                        '$match' => [
                            'product_id' => ['$in' => $productIds]
                        ]
                    ],
                    // Sort by created_at descending to get latest first
                    ['$sort' => ['created_at' => -1]],
                    // Group by product_id and take first (latest) lot with all fields
                    [
                        '$group' => [
                            '_id' => '$product_id',
                            'auction_lot' => ['$first' => '$$ROOT']
                        ]
                    ],
                    // Replace root with the auction_lot document
                    ['$replaceRoot' => ['newRoot' => '$auction_lot']]
                ];
                return $collection->aggregate($pipeline, ['allowDiskUse' => true]);
            });
            $auctionLots = collect($auctionLots);
        }

        // Step 3: Get orders that contain at least one cart_item with product_id
        $orders = Order::raw(function ($collection) {
            $pipeline = [
                [
                    '$match' => [
                        'cart_items.product_id' => ['$exists' => true, '$ne' => null]
                    ]
                ]
            ];
            return $collection->aggregate($pipeline, ['allowDiskUse' => true]);
        });
        $orders = collect($orders);

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
