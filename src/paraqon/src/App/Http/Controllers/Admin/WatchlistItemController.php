<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Arr;

// Enums
use App\Enums\LoginType;

// Models
use App\Models\Customer;
use Starsnet\Project\Paraqon\App\Models\WatchlistItem;

class WatchlistItemController extends Controller
{
    public function getAllWatchlistedCustomers(Request $request): Collection
    {
        // Exclude pagination/sorting params before filtering
        $filterParams = Arr::except($request->query(), ['per_page', 'page', 'sort_by', 'sort_order']);

        $watchlistItemQuery = WatchlistItem::query();
        foreach ($filterParams as $key => $value) {
            $watchlistItemQuery->where($key, $value);
        }

        $customerIDs = $watchlistItemQuery->get()
            ->pluck('customer_id')
            ->unique()
            ->values()
            ->all();

        // Get Customer(s)
        return Customer::whereIn('_id', $customerIDs)
            ->whereHas('account', function ($query) {
                $query->whereHas('user', function ($query2) {
                    $query2->where('type', '!=', LoginType::TEMP->value);
                });
            })
            ->with(['account', 'account.user'])
            ->get();
    }
}
