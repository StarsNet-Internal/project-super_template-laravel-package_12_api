<?php

namespace Starsnet\Project\TcgBidGame\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// Models
use Starsnet\Project\TcgBidGame\App\Models\Product;

class ProductController extends Controller
{
    public function getAllProducts(Request $request)
    {
        $category = $request->input('category');

        $query = Product::active();

        if ($category) {
            $query->byCategory($category);
        }

        $products = $query->orderBy('created_at', 'desc')
            ->get();

        return $products;
    }

    public function getProductById(Request $request)
    {
        $productId = $request->route('product_id');
        $product = Product::find($productId);

        if (!$product) {
            abort(404, json_encode([
                'en' => 'Product not found',
                'zh' => '找不到產品',
                'cn' => '找不到产品',
            ]));
        }

        return $product;
    }
}
