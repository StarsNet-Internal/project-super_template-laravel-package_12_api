<?php

namespace Starsnet\Project\Esgone\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Support\Collection;

// Enums
use App\Enums\LoginType;

// Models
use App\Models\Customer;
use App\Models\CustomerGroup;

class CustomerController extends Controller
{
    public function getAllCustomers(): array
    {
        /** @var Collection $customers */
        $customers = Customer::where('is_deleted', false)
            ->whereHas('account', function ($query) {
                $query->whereHas('user', function ($query2) {
                    $query2->where('type', '!=', LoginType::TEMP)->where('is_staff', false);
                });
            })->with([
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
            $industries = array_filter($customer['groups'], function ($group) {
                return $group['slug'] === null;
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
            $customer['company_name'] = $customer['account']['company_name'] ?? null;
            // Tree
            $customer['website'] = $customer['account']['website'] ?? null;
            $customer['short_description'] = isset($customer['account']['short_description']) ?
                $customer['account']['short_description']
                : ['en' => null, 'zh' => null, 'cn' => null];
            $customer['member_level'] = reset($memberLevel) ? reset($memberLevel)['slug'] : 'green-members';
            $customer['industries'] = array_map(function ($industry) {
                return [
                    '_id' => $industry['_id'],
                    'title' => $industry['title'],
                ];
            }, $industries);

            unset($customer['account'], $customer['groups']);
            return $customer;
        }, $customers->toArray());
    }

    public function getAllCustomerGroups(): array
    {
        /** @var Collection $groups */
        $groups = CustomerGroup::where('item_type', 'Customer')
            ->where('is_system', false)
            ->whereNull('slug')
            ->statusActive()
            ->with([
                'customers' => function ($customer) {
                    $customer->where('is_deleted', false);
                },
                'customers.account',
                'customers.groups' => function ($group) {
                    $group->where('is_system', false);
                },
            ])
            ->get();

        // Append keys
        $groups = $groups->toArray();
        foreach ($groups as $groupKey => $group) {
            foreach ($groups[$groupKey]['customers'] as $customerKey => $customer) {
                $memberLevel = array_filter($groups[$groupKey]['customers'][$customerKey]['groups'], function ($group) {
                    return $group['slug'] !== null;
                });
                $industries = array_filter($groups[$groupKey]['customers'][$customerKey]['groups'], function ($group) {
                    return $group['slug'] === null;
                });

                $groups[$groupKey]['customers'][$customerKey]['user'] = [
                    'username' => $groups[$groupKey]['customers'][$customerKey]['account']['username'],
                    'avatar' => $groups[$groupKey]['customers'][$customerKey]['account']['avatar'],
                ];
                $groups[$groupKey]['customers'][$customerKey]['country'] = $groups[$groupKey]['customers'][$customerKey]['account']['country'];
                $groups[$groupKey]['customers'][$customerKey]['gender'] = $groups[$groupKey]['customers'][$customerKey]['account']['gender'];
                $groups[$groupKey]['customers'][$customerKey]['email'] = $groups[$groupKey]['customers'][$customerKey]['account']['email'];
                $groups[$groupKey]['customers'][$customerKey]['area_code'] = $groups[$groupKey]['customers'][$customerKey]['account']['area_code'];
                $groups[$groupKey]['customers'][$customerKey]['phone'] = $groups[$groupKey]['customers'][$customerKey]['account']['phone'];
                $groups[$groupKey]['customers'][$customerKey]['company_name'] = $groups[$groupKey]['customers'][$customerKey]['account']['company_name'] ?? null;
                // Tree
                $groups[$groupKey]['customers'][$customerKey]['website'] = $groups[$groupKey]['customers'][$customerKey]['account']['website'] ?? null;
                $groups[$groupKey]['customers'][$customerKey]['short_description'] = isset($groups[$groupKey]['customers'][$customerKey]['account']['short_description']) ?
                    $groups[$groupKey]['customers'][$customerKey]['account']['short_description']
                    : ['en' => null, 'zh' => null, 'cn' => null];
                $groups[$groupKey]['customers'][$customerKey]['member_level'] = reset($memberLevel);
                $groups[$groupKey]['customers'][$customerKey]['industries'] = array_values(array_map(function ($industry) {
                    return [
                        '_id' => $industry['_id'],
                        'title' => $industry['title'],
                    ];
                }, $industries));

                unset($groups[$groupKey]['customers'][$customerKey]['account'], $groups[$groupKey]['customers'][$customerKey]['groups']);
            }
            $groups[$groupKey]['customers'] = array_filter($groups[$groupKey]['customers'], function ($customer) {
                return $customer['member_level']['slug'] !== 'website-members';
            });
            $groups[$groupKey]['customers'] = array_values($groups[$groupKey]['customers']);
        }

        return $groups;
    }
}
