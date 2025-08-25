<?php

namespace Starsnet\Project\Auction\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// Models
use App\Models\Product;

class ProductController extends Controller
{
    public function massUpdateProducts(Request $request): array
    {
        $productAttrbutes = $request->products;

        foreach ($productAttrbutes as $attributes) {
            /** @var ?Product $product */
            $product = Product::find($attributes['id']);

            // Check if the Product exists
            if (!is_null($product)) {
                $updateAttributes = $attributes;
                unset($updateAttributes['id']);
                $product->update($updateAttributes);
            }
        }

        return ['message' => 'Products updated successfully'];
    }
}
