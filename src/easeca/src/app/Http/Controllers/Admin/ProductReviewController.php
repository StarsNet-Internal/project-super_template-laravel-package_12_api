<?php

namespace StarsNet\Project\Easeca\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// Models
use App\Models\ProductReview;

class ProductReviewController extends Controller
{
    public function getReviewDetails(Request $request): ProductReview
    {
        /** @var ?ProductReview $review */
        $review = ProductReview::find($request->route('id'))
            ->makeHidden([
                'product_title',
                'product_variant_title',
                'image'
            ]);
        if (is_null($review)) abort(404, 'Review not found');

        return $review;
    }
}
