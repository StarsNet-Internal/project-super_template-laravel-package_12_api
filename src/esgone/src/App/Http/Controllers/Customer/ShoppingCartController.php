<?php

namespace Starsnet\Project\Esgone\App\Http\Controllers\Customer;

// Laravel built-in
use Illuminate\Http\Request;

// Models
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\ShoppingCartItem;

// Controllers
use App\Http\Controllers\Customer\ShoppingCartController as CustomerShoppingCartController;

class ShoppingCartController extends CustomerShoppingCartController
{
    public function getAll(Request $request): array
    {
        $data = parent::getAll($request);

        // Append ProductVariant per cart_item
        foreach ($data['cart_items'] as $key => $item) {
            $productVariantId = $item['product_variant_id'];
            $variant = ProductVariant::find($productVariantId);
            $data['cart_items'][$key]['variant'] = $variant;
        }

        return $data;
    }

    public function getRelatedProductsUrls(Request $request): array
    {
        $now = now();

        // Extract attributes from $request
        $excludedProductIDs = $request->input('exclude_ids', []);
        $itemsPerPage = $request->items_per_page;
        $type = $request->input('type', 'open');

        // Get authenticated User information
        $customer = $this->customer();

        // Get first valid product_id
        $cartItems = ShoppingCartItem::where('customer_id', $customer->id)
            ->where('store_id', $this->store->id)
            ->get();
        $addedProductIDs = $cartItems->pluck('product_id')->all();
        $productID = count($addedProductIDs) > 0 ? $addedProductIDs[0] : null;

        if (!is_null($productID)) {
            /** @var Product $product */
            $product = Product::find($productID);
            $excludedProductIDs[] = $product->_id;
        }

        // Initialize a Product collector
        $products = [];

        /*
        *   Stage 1:
        *   Get Product(s) from System ProductCategory, recommended-products
        */
        $systemCategory = ProductCategory::where('slug', 'recommended-products')->first();

        if (!is_null($systemCategory)) {
            // Get Product(s)
            $recommendedProducts = $systemCategory->products()
                ->when($type !== 'all', function ($query) use ($type, $now) {
                    if ($type === 'open') {
                        return $query->whereHas('variants', function ($query2) use ($now) {
                            $query2->where('long_description.en', '>', $now);
                        });
                    } elseif ($type === 'closed') {
                        return $query->whereHas('variants', function ($query2) use ($now) {
                            $query2->where('long_description.en', '<', $now);
                        });
                    }
                })
                ->statusActive()
                ->excludeIDs($excludedProductIDs)
                ->get();
            $recommendedProducts = $recommendedProducts->shuffle();

            // Collect data
            $products = array_merge($products, $recommendedProducts->all()); // collect Product(s)
            $excludedProductIDs = array_merge($excludedProductIDs, $recommendedProducts->pluck('id')->all()); // collect _id
        }

        /*
        *   Stage 2:
        *   Get Product(s) from active, related ProductCategory(s)
        */
        if (isset($product) && !is_null($product)) {
            // Get related ProductCategory(s) by Product and within Store
            $relatedCategories = $product->categories()
                ->storeID($this->store)
                ->statusActive()
                ->get();

            $relatedCategoryIDs = $relatedCategories->pluck('id')->all();

            // Get Product(s)
            $relatedProducts = Product::whereHas('categories', function ($query) use ($relatedCategoryIDs) {
                $query->whereIn('_id', $relatedCategoryIDs);
            })
                ->when($type !== 'all', function ($query) use ($type, $now) {
                    if ($type === 'open') {
                        return $query->whereHas('variants', function ($query2) use ($now) {
                            $query2->where('long_description.en', '>', $now);
                        });
                    } elseif ($type === 'closed') {
                        return $query->whereHas('variants', function ($query2) use ($now) {
                            $query2->where('long_description.en', '<', $now);
                        });
                    }
                })
                ->statusActive()
                ->excludeIDs($excludedProductIDs)
                ->get();

            $relatedProducts = $relatedProducts->shuffle(); // randomize ordering

            // Collect data
            $products = array_merge($products, $relatedProducts->all()); // collect Product(s)
            $excludedProductIDs = array_merge($excludedProductIDs, $relatedProducts->pluck('id')->all()); // collect _id
        }

        /*
        *   Stage 3:
        *   Get Product(s) assigned to this Store's active ProductCategory(s)
        */
        // Get remaining ProductCategory(s) by Store
        if (!isset($relatedCategoryIDs)) $relatedCategoryIDs = [];
        $otherCategories = $this->store
            ->productCategories()
            ->statusActive()
            ->excludeIDs($relatedCategoryIDs)
            ->get();

        if ($otherCategories->count() > 0) {
            $otherCategoryIDs = $otherCategories->pluck('id')->all();

            // Get Product(s)
            $otherProducts = Product::whereHas('categories', function ($query) use ($otherCategoryIDs) {
                $query->whereIn('_id', $otherCategoryIDs);
            })
                ->when($type !== 'all', function ($query) use ($type, $now) {
                    if ($type === 'open') {
                        return $query->whereHas('variants', function ($query2) use ($now) {
                            $query2->where('long_description.en', '>', $now);
                        });
                    } elseif ($type === 'closed') {
                        return $query->whereHas('variants', function ($query2) use ($now) {
                            $query2->where('long_description.en', '<', $now);
                        });
                    }
                })
                ->statusActive()
                ->excludeIDs($excludedProductIDs)
                ->get();

            $otherProducts = $otherProducts->shuffle();

            // Collect data
            $products = array_merge($products, $otherProducts->all());
        }

        /*
        *   Stage 4:
        *   Generate URLs
        */
        return collect($products)
            ->pluck('id')
            ->chunk($itemsPerPage)
            ->map(fn($chunk) => route('esgone.products.ids', [
                'store_id' => $this->store->_id,
                'ids' => $chunk->all()
            ]))
            ->all();
    }
}
