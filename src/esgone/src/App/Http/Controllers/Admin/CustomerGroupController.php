<?php

namespace Starsnet\Project\Esgone\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// Models
use App\Models\Customer;
use App\Models\CustomerGroup;

class CustomerGroupController extends Controller
{
    public function getCustomerGroupAssignedCustomers(Request $request): array
    {
        /** @var ?CustomerGroup $category */
        $category = CustomerGroup::find($request->route('id'));
        if (is_null($category)) abort(404, 'CustomerGroup not found');

        /** @var Collection $customers */
        $customers = $category->customers()
            ->whereHas('account', function ($query) {
                $query->whereHas('user', function ($query2) {
                    $query2->where('is_staff', false);
                });
            })
            ->where('is_deleted', false)
            ->with([
                'account',
                'groups' => function ($group) {
                    $group->where('is_system', false);
                },
            ])
            ->get();

        // Append keys
        return array_map(function ($customer) {
            $memberLevel = array_filter($customer['groups'], function ($group) {
                return $group['slug'] !== null;
            });

            $customer['user'] = [
                'username' => $customer['account']['username'],
                'avatar' => $customer['account']['avatar'],
            ];
            $customer['country'] = $customer['account']['country'];
            $customer['gender'] = $customer['account']['gender'];
            $customer['email'] = $customer['account']['email'];
            $customer['area_code'] = $customer['account']['area_code'];
            $customer['phone'] = $customer['account']['phone'];
            $customer['member_level'] = reset($memberLevel) ? reset($memberLevel)['slug'] : null;

            unset($customer['account']);
            return $customer;
        }, $customers->toArray());
    }

    public function getCustomerGroupUnassignedCustomers(Request $request): array
    {
        /** @var ?CustomerGroup $category */
        $category = CustomerGroup::find($request->route('id'));
        if (is_null($category)) abort(404, 'CustomerGroup not found');

        /** @var Collection $customers */
        $customers = Customer::excludeIDs($category->item_ids)
            ->whereHas('account', function ($query) {
                $query->whereHas('user', function ($query2) {
                    $query2->where('is_staff', false);
                });
            })
            ->where('is_deleted', false)
            ->with([
                'account',
                'groups' => function ($group) {
                    $group->where('is_system', false);
                },
            ])
            ->get();

        // Append keys
        return array_map(function ($customer) {
            $memberLevel = array_filter($customer['groups'], function ($group) {
                return $group['slug'] !== null;
            });

            $customer['user'] = [
                'username' => $customer['account']['username'],
                'avatar' => $customer['account']['avatar'],
            ];
            $customer['country'] = $customer['account']['country'];
            $customer['gender'] = $customer['account']['gender'];
            $customer['email'] = $customer['account']['email'];
            $customer['area_code'] = $customer['account']['area_code'];
            $customer['phone'] = $customer['account']['phone'];
            $customer['member_level'] = reset($memberLevel) ? reset($memberLevel)['slug'] : null;

            unset($customer['account']);
            return $customer;
        }, $customers->toArray());
    }
}
