<?php

namespace Starsnet\Project\Rmhc\App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Support\Collection;

use App\Models\Customer;
use App\Enums\LoginType;

class CustomerController extends Controller
{
    public function getAllCustomers(): Collection
    {
        /** @var Collection $customers */
        $customers = Customer::whereHas('account.user', function ($query) {
            $query->where('is_deleted', false)
                ->where('type', '!=', LoginType::TEMP->value);
        })
            ->get();

        foreach ($customers as $customer) {
            $account = $customer->account;
            $user = $account->user;

            $customer->user = [
                'username' => $account->username,
                'avatar' => $account->avatar,
            ];
            $customer->country = $account->country;
            $customer->gender = $account->gender;
            $customer->last_logged_in_at = optional($user)->last_logged_in_at;
            $customer->email = $account->email;
            $customer->area_code = $account->area_code;
            $customer->phone = $account->phone;
        }

        return $customers;
    }
}
