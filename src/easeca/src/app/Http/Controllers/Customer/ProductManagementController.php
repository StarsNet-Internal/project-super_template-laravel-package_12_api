<?php

namespace StarsNet\Project\Easeca\App\Http\Controllers\Customer;

use App\Http\Controllers\Customer\ProductManagementController as CustomerProductManagementController;
use Illuminate\Http\Request;

// Traits
use StarsNet\Project\Easeca\App\Http\Controllers\Traits\ProjectProductTrait;

// Enums
use App\Enums\Status;

// Models
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Models\Alias;
use App\Models\Configuration;
use Illuminate\Support\Collection;

class ProductManagementController extends CustomerProductManagementController
{
    use ProjectProductTrait;

    /** @var Store $store */
    protected $store;

    public function getStoreByAccount(Request $request)
    {
        $account = $this->account();

        if ($account->store_id != null) {
            $this->store = Store::find($account->store_id)
                ?? Store::find(Alias::getValue($account->store_id));
        } else {
            $this->store = Store::find($request->route('store_id'))
                ?? Store::find(Alias::getValue($request->route('store_id')));
        }
    }

    public function getAllProductCategories(Request $request): Collection
    {
        $this->getStoreByAccount($request);
        return parent::getAllProductCategories($request);
    }

    public function getAllProductCategoryHierarchy(Request $request): Collection
    {
        $this->getStoreByAccount($request);
        return parent::getAllProductCategoryHierarchy($request);
    }

    public function filterProductsByCategories(Request $request): Collection
    {
        $this->getStoreByAccount($request);

        // Extract attributes from $request
        $categoryIDs = (array) $request->input('category_ids');
        $slug = $request->input('slug', 'by-keyword-relevance');

        // Get sorting attributes via slugs
        if ($slug) {
            $config = Configuration::where('slug', 'product-sorting')->latest()->first();
            $foundItem = collect($config?->sorting_list ?? [])->firstWhere('slug', $slug);
            if ($foundItem && $foundItem['type'] === 'KEY') {
                $request->merge([
                    'sort_by' => $foundItem['key'],
                    'sort_order' => $foundItem['ordering']
                ]);
            }
        }

        // Get all ProductCategory(s)
        if (count($categoryIDs) === 0) {
            $categoryIDs = $this->store
                ->productCategories()
                ->statusActive()
                ->get()
                ->pluck('id')
                ->all();
        }

        // Get Product(s) from selected ProductCategory(s)
        $productIDs = Product::whereHas('categories', function ($query) use ($categoryIDs) {
            $query->whereIn('_id', $categoryIDs);
        })
            ->statusActive()
            ->get()
            ->pluck('id')
            ->all();

        return $this->getProductInfoByAggregation($productIDs);
    }

    public function getRelatedProductsUrls(Request $request): array
    {
        $this->getStoreByAccount($request);

        // Extract attributes from $request
        $productID = $request->input('product_id');
        $excludedProductIDs = (array) $request->input('exclude_ids');
        $itemsPerPage = $request->input('items_per_page');

        // Append to excluded Product
        $excludedProductIDs[] = $productID;

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
                ->statusActive()
                ->excludeIDs($excludedProductIDs)
                ->get();
            $recommendedProducts = $recommendedProducts->shuffle(); // randomize ordering

            // Collect data
            $products = array_merge($products, $recommendedProducts->all()); // collect Product(s)
            $excludedProductIDs = array_merge($excludedProductIDs, $recommendedProducts->pluck('id')->all()); // collect _id
        }

        /*
        *   Stage 2:
        *   Get Product(s) from active, related ProductCategory(s)
        */
        $product = Product::find($productID);

        if (!is_null($product)) {
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
                ->statusActive()
                ->excludeIDs($excludedProductIDs)
                ->get();

            // Randomize ordering
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
                ->statusActive()
                ->excludeIDs($excludedProductIDs)
                ->get();

            // Randomize ordering
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
            ->map(fn($chunk) => route('easeca.products.ids', [
                'store_id' => $this->store->_id,
                'ids' => $chunk->all()
            ]))
            ->all();
    }

    public function getProductsByIDs(Request $request): Collection
    {
        return $this->getProductInfoByAggregation($request->ids);
    }
}
