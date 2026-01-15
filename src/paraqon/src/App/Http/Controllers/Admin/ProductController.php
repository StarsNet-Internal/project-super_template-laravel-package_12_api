<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Enums
use App\Enums\Status;
use App\Enums\ProductVariantDiscountType;

// Models
use App\Models\Product;

class ProductController extends Controller
{
    public function getAllProducts(Request $request): Collection
    {
        // Extract attributes from $request
        $statuses = (array) $request->input('status', Status::defaultStatuses());

        $getKeys = ['_id', 'title', 'images', 'status', 'updated_at', 'created_at', 'product_interface', 'prefix', 'stock_no', 'owned_by_customer_id', 'reserve_price', 'bid_incremental_settings', 'seller_id', 'buyer_id'];
        /** @var Collection $products */
        $products = Product::statusesAllowed(Status::defaultStatuses(), $statuses)
            ->with([
                'variants' => function ($productVariant) {
                    $productVariant->with([
                        'discounts' => function ($discount) {
                            $discount->applicableForCustomer()->select('product_variant_id', 'type', 'value', 'start_datetime', 'end_datetime');
                        },
                    ])
                        ->statuses([Status::DRAFT->value, Status::ACTIVE->value, Status::ARCHIVED->value])
                        ->select('product_id', 'price', 'point');
                },
                'reviews',
                'wishlistItems',
                'warehouseInventories'
            ])->get($getKeys);

        foreach ($products as $product) {
            // Collect ProductVariants, and calculate the discountedPrice
            $collectedVariants = collect($product->variants->map(
                function ($variant) {
                    $discountedPrice = $variant->price;

                    if ($variant->discounts->count() > 0) {
                        $selectedDiscount = $variant->discounts[0];

                        switch ($selectedDiscount['type']) {
                            case ProductVariantDiscountType::PRICE->value:
                                $discountedPrice -= $selectedDiscount['value'];
                                break;
                            case ProductVariantDiscountType::PERCENTAGE->value:
                                $discountedPrice *= (1 - $selectedDiscount['value'] / 100);
                                break;
                            default:
                                break;
                        }
                    }

                    $variant['discounted_price'] = $discountedPrice;
                    return $variant;
                }
            ));
            $collectedReviews = collect($product->reviews);

            // Append attributes
            $product['min_original_price'] = max($collectedVariants->min('price'), 0) ?? 0;
            $product['max_original_price'] = max($collectedVariants->max('price'), 0) ?? 0;
            $product['min_discounted_price'] = (string) max($collectedVariants->min('discounted_price'), 0) ?? 0;
            $product['max_discounted_price'] = (string) max($collectedVariants->max('discounted_price'), 0) ?? 0;
            $product['min_point'] = max($collectedVariants->min('point'), 0) ?? 0;
            $product['max_point'] = max($collectedVariants->max('point'), 0) ?? 0;
            $product['rating'] = $collectedReviews->avg('rating') ?? 0;
            $product['review_count'] = $collectedReviews->count() ?? 0;
            $product['inventory_count'] = collect($product->warehouseInventories)->sum('qty') ?? 0;
            $product['wishlist_item_count'] = collect($product->wishlistItems)->count() ?? 0;
            $product['first_variant_id'] = optional($collectedVariants->first())->_id;
            $product['product_interface'] = $product->product_interface;
            $product['prefix'] = $product->prefix;
            $product['stock_no'] = $product->stock_no;
            $product['owned_by_customer_id'] = $product->owned_by_customer_id;
            $product['seller_id'] = $product->seller_id;
            $product['buyer_id'] = $product->buyer_id;
            $product['reserve_price'] = $product->reserve_price;
            $product['bid_incremental_settings'] = $product->bid_incremental_settings;

            unset($product['variants'], $product['reviews'], $product['warehouseInventories'], $product['wishlistItems']);
        }

        return $products;
    }

    public function createProduct(Request $request): array
    {
        // Create Product
        /** @var Product $product */
        $product = Product::create($request->except(['stock_no']));

        $yearPrefix = date('y'); // e.g. "25"
        $pattern = '/^' . $yearPrefix . '\d{6}$/';

        // Get the largest stock_no matching {yy}{6 digits}
        $maxStockNo = Product::where('stock_no', 'regexp', $pattern)->max('stock_no');

        $nextIncrement = 1;
        if ($maxStockNo) $nextIncrement = (int) substr($maxStockNo, 2) + 1;

        $stockNo = $yearPrefix . str_pad($nextIncrement, 6, '0', STR_PAD_LEFT);

        $product->update(['stock_no' => $stockNo]);

        return [
            'message' => 'Created New Product successfully',
            '_id' => $product->_id
        ];
    }
}
