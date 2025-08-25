<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Models
use App\Models\ShoppingCartItem;

class ShoppingCartController extends Controller
{
    public function getShoppingCartItems(Request $request): Collection
    {
        return ShoppingCartItem::where('store_id', $request->store_id)
            ->where('customer_id', $request->customer_id)
            ->get()
            ->append([
                // Product information related
                'product_title',
                'product_variant_title',
                'image',
                // Calculation-related
                'local_discount_type',
                'original_price_per_unit',
                'discounted_price_per_unit',
                'original_subtotal_price',
                'subtotal_price',
                'original_point_per_unit',
                'discounted_point_per_unit',
                'original_subtotal_point',
                'subtotal_point',
            ])
            ->each(function ($item) {
                $item->is_checkout = true;
                $item->is_refundable = false;
                $item->global_discount = null;
            });
    }
}
