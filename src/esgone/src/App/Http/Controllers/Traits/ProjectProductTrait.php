<?php

namespace Starsnet\Project\Esgone\App\Http\Controllers\Traits;

// Laravel
use Illuminate\Support\Collection;

// Models
use App\Models\Product;

trait ProjectProductTrait
{
    private function getProductsInfoByEagerLoading(array $productIDs): Collection
    {
        $hiddenKeys = [
            'discount',
            'remarks',
            'status',
            'is_system',
            'deleted_at',
            'reviews',
            'warehouse_inventories',
            'wishlist_items'
        ];

        $products = Product::with([
            'variants' => function ($productVariant) {
                $productVariant
                    ->statusActive()
                    ->get();
            },
        ])
            ->whereIn('_id', $productIDs)
            ->append(['first_product_variant_id', 'price', 'point']);

        foreach ($products as $product) {
            $product['local_discount_type'] = null;
            $product['global_discount'] = null;
            $product['rating'] = null;
            $product['review_count'] = 0;
            $product['inventory_count'] = 0;
            $product['wishlist_item_count'] = 0;
            $product['is_liked'] = false;
            $product['discounted_price'] = strval($product['price'] ?? 0);

            foreach ($hiddenKeys as $hiddenKey) {
                unset($product[$hiddenKey]);
            }

            foreach ($product['variants'] as $variant) {
                $variant['inventory_count'] = collect($variant->warehouseInventories)->sum('qty') ?? 0;
                unset($variant['warehouseInventories']);
            }
        }

        return $products;
    }
}
