<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Enums
use App\Enums\LoginType;

// Models
use App\Models\Customer;
use App\Models\CustomerGroup;

class CustomerGroupController extends Controller
{
    public function getCustomerGroupAssignedCustomers(Request $request): Collection
    {
        /** @var ?CustomerGroup $category */
        $category = CustomerGroup::find($request->route('id'));
        if (is_null($category)) abort(404, 'CustomerGroup not found');

        return $category->customers()
            ->with(['account',  'account.user'])
            ->get();
    }

    public function getCustomerGroupUnassignedCustomers(Request $request): Collection
    {
        /** @var ?CustomerGroup $category */
        $category = CustomerGroup::find($request->route('id'));
        if (is_null($category)) abort(404, 'CustomerGroup not found');

        return Customer::with(['account'])
            ->excludeIDs($category->item_ids)
            ->whereHas('account', function ($query) {
                $query->whereHas('user', function ($query2) {
                    $query2->where('type', '!=', LoginType::TEMP->value);
                });
            })
            ->get();
    }
}
