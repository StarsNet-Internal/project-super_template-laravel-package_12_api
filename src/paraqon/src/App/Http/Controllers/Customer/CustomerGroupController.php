<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// Models
use App\Models\CustomerGroup;

use App\Enums\Status;
use Illuminate\Support\Collection;

class CustomerGroupController extends Controller
{
    public function filterCustomerGroups(Request $request): Collection
    {
        $hasSlug = $request->filled('slug');
        $hasSlugs = $request->has('slugs');
        $hasStripeProductId = $request->filled('stripe_product_id');
        $hasStripeProductIds = $request->has('stripe_product_ids');

        if (!$hasSlug && !$hasSlugs && !$hasStripeProductId && !$hasStripeProductIds) {
            abort(400, 'At least one filter is required: slug, slugs, stripe_product_id, or stripe_product_ids');
        }

        if ($hasSlug && $hasSlugs) {
            abort(400, 'Provide either slug or slugs, not both');
        }

        if ($hasStripeProductId && $hasStripeProductIds) {
            abort(400, 'Provide either stripe_product_id or stripe_product_ids, not both');
        }

        if ($hasSlug && !is_string($request->input('slug'))) {
            abort(400, 'slug must be a string');
        }

        if ($hasSlugs) {
            $slugs = $request->input('slugs');
            if (!is_array($slugs)) abort(400, 'slugs must be an array');
            if (count($slugs) === 0) abort(400, 'slugs must contain at least one value');

            foreach ($slugs as $slug) {
                if (!is_string($slug) || $slug === '') {
                    abort(400, 'Each value in slugs must be a non-empty string');
                }
            }
        }

        if ($hasStripeProductId && !is_string($request->input('stripe_product_id'))) {
            abort(400, 'stripe_product_id must be a string');
        }

        if ($hasStripeProductIds) {
            $stripeProductIds = $request->input('stripe_product_ids');
            if (!is_array($stripeProductIds)) abort(400, 'stripe_product_ids must be an array');
            if (count($stripeProductIds) === 0) abort(400, 'stripe_product_ids must contain at least one value');

            foreach ($stripeProductIds as $stripeProductId) {
                if (!is_string($stripeProductId) || $stripeProductId === '') {
                    abort(400, 'Each value in stripe_product_ids must be a non-empty string');
                }
            }
        }

        $query = CustomerGroup::where('status', Status::ACTIVE->value);

        if ($request->filled('slug')) {
            $query = $this->applySlugPatterns($query, [$request->input('slug')]);
        } elseif ($request->has('slugs')) {
            $query = $this->applySlugPatterns($query, (array) $request->input('slugs', []));
        }

        if ($request->filled('stripe_product_id')) {
            $query->where('stripe_product_id', $request->input('stripe_product_id'));
        } elseif ($request->has('stripe_product_ids')) {
            $stripeProductIds = collect((array) $request->input('stripe_product_ids', []))
                ->filter(fn($id) => is_string($id) && $id !== '')
                ->unique()
                ->values()
                ->all();

            if (count($stripeProductIds) > 0) {
                $query->whereIn('stripe_product_id', $stripeProductIds);
            }
        }

        return $query->get();
    }

    private function applySlugPatterns($query, array $patterns)
    {
        $patterns = collect($patterns)
            ->filter(fn($pattern) => is_string($pattern) && $pattern !== '')
            ->unique()
            ->values()
            ->all();

        if (count($patterns) === 0) {
            return $query;
        }

        $orConditions = [];
        foreach ($patterns as $pattern) {
            if ($this->isRegexSlug($pattern)) {
                [$regex, $options] = $this->parseRegexSlug($pattern);
                $condition = ['$regex' => $regex];
                if ($options !== '') {
                    $condition['$options'] = $options;
                }
                $orConditions[] = ['slug' => $condition];
                continue;
            }

            // Plain strings match anywhere in slug (e.g. "the-vault" matches "the-vault-vip")
            $orConditions[] = ['slug' => ['$regex' => preg_quote($pattern, '/')]];
        }

        return $query->whereRaw(['$or' => $orConditions]);
    }

    private function isRegexSlug(string $value): bool
    {
        return str_starts_with($value, '/') && preg_match('/^\/(.+)\/[a-z]*$/i', $value) === 1;
    }

    private function parseRegexSlug(string $value): array
    {
        preg_match('/^\/(.+)\/([a-z]*)/i', $value, $matches);

        return [$matches[1], $matches[2] ?? ''];
    }
}
