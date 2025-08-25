<?php

namespace StarsNet\Project\Easeca\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Enums
use App\Enums\Status;

// Models
use App\Models\Alias;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;

class OnlineStoreManagementController extends Controller
{
    /** @var Store $store */
    protected $store;

    public function __construct(Request $request)
    {
        $this->store = Store::find($request->route('store_id'))
            ?? Store::find(Alias::getValue($request->route('store_id')));
    }

    public function getCategoryUnassignedProducts(Request $request): Collection
    {
        // Extract attributes from $request
        $statuses = (array) $request->input('status', Status::defaultStatuses());

        /** @var ProductCategory $category */
        $category = ProductCategory::find($request->route('category_id'));
        if (is_null($category)) abort(404, 'ProductCategory not found');

        return Product::where('store_id', $category->model_type_id)
            ->excludeIDs($category->item_ids)
            ->statusesAllowed(Status::defaultStatuses(), $statuses)
            ->get();
    }
}
