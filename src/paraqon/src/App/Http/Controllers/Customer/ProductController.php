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
    public function getAllOwnedProducts(): Collection
    {
        $customer = $this->customer();
        $customerId = $customer->id;

        $ownedProductIds = collect();

        // Step 1: Get products from Order (highest priority) - latest order per product
        $orderProductIds = Order::raw(function ($collection) use ($customerId) {
            $pipeline = [
                // Unwind cart_items to get individual items
                ['$unwind' => '$cart_items'],
                // Match orders with customer_id and non-null product_id
                [
                    '$match' => [
                        'customer_id' => $customerId,
                        'cart_items.product_id' => ['$exists' => true, '$ne' => null]
                    ]
                ],
                // Sort by created_at descending to get latest first
                ['$sort' => ['created_at' => -1]],
                // Group by product_id and take first (latest) order
                [
                    '$group' => [
                        '_id' => '$cart_items.product_id',
                        'order_created_at' => ['$first' => '$created_at']
                    ]
                ],
                // Project just the product_id
                ['$project' => ['product_id' => '$_id', '_id' => 0]]
            ];
            return $collection->aggregate($pipeline, ['allowDiskUse' => true]);
        });
        $orderProductIds = collect($orderProductIds)->pluck('product_id')->all();
        $ownedProductIds = $ownedProductIds->merge($orderProductIds);

        // Step 2: Get products from AuctionLot (medium priority) - latest lot per product
        // Exclude products already found in Orders (since Order has higher priority)
        $auctionLotProductIds = AuctionLot::raw(function ($collection) use ($customerId, $orderProductIds) {
            $pipeline = [
                // Match lots with owned_by_customer_id and non-null product_id
                [
                    '$match' => [
                        'owned_by_customer_id' => $customerId,
                        'product_id' => ['$exists' => true, '$ne' => null]
                    ]
                ],
                // Exclude products already found in Orders (higher priority)
                ...(count($orderProductIds) > 0 ? [
                    ['$match' => ['product_id' => ['$nin' => $orderProductIds]]]
                ] : []),
                // Sort by created_at descending
                ['$sort' => ['created_at' => -1]],
                // Group by product_id and take first (latest) lot
                [
                    '$group' => [
                        '_id' => '$product_id',
                        'lot_created_at' => ['$first' => '$created_at']
                    ]
                ],
                // Project just the product_id
                ['$project' => ['product_id' => '$_id', '_id' => 0]]
            ];
            return $collection->aggregate($pipeline, ['allowDiskUse' => true]);
        });
        $auctionLotProductIds = collect($auctionLotProductIds)->pluck('product_id')->all();
        $ownedProductIds = $ownedProductIds->merge($auctionLotProductIds);

        // Step 3: Get products from Product.owned_by_customer_id (lowest priority)
        // Exclude products already found in Orders or AuctionLots
        $alreadyFoundIds = $ownedProductIds->unique()->all();
        $productFieldIds = Product::where('owned_by_customer_id', $customerId)
            ->when(count($alreadyFoundIds) > 0, function ($query) use ($alreadyFoundIds) {
                return $query->whereNotIn('_id', $alreadyFoundIds);
            })
            ->pluck('id')
            ->all();
        $ownedProductIds = $ownedProductIds->merge($productFieldIds);

        // Step 4: Get unique product IDs and fetch the products
        $uniqueProductIds = $ownedProductIds->unique()->all();

        if (empty($uniqueProductIds)) {
            return collect();
        }

        return Product::whereIn('_id', $uniqueProductIds)
            ->statusActive()
            ->with([
                'variants' => function ($productVariant) {
                    $productVariant->statusActive()->get();
                }
            ])
            ->get();
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
