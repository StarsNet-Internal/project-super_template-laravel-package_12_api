<?php

namespace Starsnet\Project\Esgone\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

// Enums
use App\Enums\LoginType;

// Models
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\NotificationSetting;
use App\Models\Role;
use App\Models\User;

class CustomerController extends Controller
{
    public function getAllCustomers(): array
    {
        /** @var Collection $customers */
        $customers = Customer::where('is_deleted', false)
            ->whereHas('account', function ($query) {
                $query->whereHas('user', function ($query2) {
                    $query2->where('type', '!=', LoginType::TEMP->value)
                        ->where('is_staff', false);
                });
            })->with([
                'account',
                'groups' => function ($group) {
                    $group->where('is_system', false);
                },
            ])
            ->get();

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
            // Tree
            $customer['member_level'] = reset($memberLevel) ? reset($memberLevel)['slug'] : null;

            unset($customer['groups']);
            return $customer;
        }, $customers->toArray());
    }

    public function getCustomerDetails(Request $request): Customer
    {
        /** @var ?Customer $customer */
        $customer = Customer::with(['account', 'account.user'])
            ->find($request->route('id'))
            ->append(['order_statistics']);
        if (is_null($customer)) abort(404, 'Customer not found');

        // Append keys
        $customer['user'] = [
            'username' => $customer['account']['username'],
            'avatar' => $customer['account']['avatar'],
        ];
        $customer['country'] = $customer['account']['country'];
        $customer['gender'] = $customer['account']['gender'];
        $customer['last_logged_in_at'] = $customer['account']['user']['last_logged_in_at'];
        $customer['email'] = $customer['account']['email'];
        $customer['area_code'] = $customer['account']['area_code'];
        $customer['phone'] = $customer['account']['phone'];
        unset($customer['account']['user']);

        return $customer;
    }

    public function createCustomer(Request $request): array
    {
        $now = now();

        // Extract attributes from $request
        $registrationType = $request->input('type', LoginType::EMAIL->value);
        $email = $request->email;
        $password = $request->password ?? 'password';

        /** @var ?Role $role */
        $role = Role::where('slug', 'customer')->first();
        if (is_null($role)) abort(404, 'Customer Role not found');

        // Generate a new customer-identity Account
        $user = User::create([
            'type' => $registrationType,
            'login_id' => $email,
            'password' => Hash::make($password),
            'is_staff' => true,
            'password_updated_at' => $now
        ]);

        // Create Account
        $account = Account::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'username' => $request->username ?? substr($email, 0, strrpos($email, '@')),
            'avatar' => $request->avatar,
            'email' => $email,
            'area_code' => $request->area_code,
            'phone' => $request->phone,
        ]);

        // Create Customer
        /** @var Customer $customer */
        $customer = Customer::create([
            'account_id' => $account->id,
            'delivery_recipient' => [
                'name' => $account->username,
                'address' => $request->address,
                'area_code' => $request->area_code,
                'phone' => $request->phone,
            ]
        ]);
        /** @var ?CustomerGroup $customer */
        $allCustomerGroup = CustomerGroup::where('slug', 'all-customers')->first();
        if (!is_null($allCustomerGroup)) {
            $customer->attachGroups(collect($allCustomerGroup));
        }

        // Create NotificationSetting
        NotificationSetting::create([
            'account_id' => $account->id,
        ]);

        return [
            'message' => 'Created New Customer successfully',
            '_id' => $user->id,
            'customer_id' => $customer->id
        ];
    }

    public function updateCustomerDetails(Request $request): array
    {
        /** @var ?Customer $customer */
        $customer = Customer::find($request->route('id'));
        if (is_null($customer)) abort(404, 'Customer not found');

        /** @var ?Account $account */
        $account = $customer->account;
        if (is_null($account)) abort(404, 'Account not found');

        /** @var ?User $user */
        $user = $account->user;
        if (is_null($user)) abort(404, 'User not found');

        // Check if matching User loginID exists
        $loginID = null;
        switch ($user->type) {
            case LoginType::EMAIL->value:
                $loginID = $request->email;
                break;
            case LoginType::PHONE->value:
                $loginID = $request->area_code . $request->phone;
                break;
            default:
                break;
        }

        $isTaken = User::where('id', '!=', $user->id)
            ->where('type', $user->type)
            ->where('login_id', $loginID)
            ->exists();
        if ($isTaken === true) abort(400, 'loginID has already been taken');

        // Update User
        $user->update(['login_id' => $loginID]);

        // Update Account
        $attributes = [
            'username' => $request->username,
            'avatar' => $request->avatar,
            'email' => $request->email,
            'area_code' => $request->area_code,
            'phone' => $request->phone,
            'gender' => $request->gender,
            'country' => $request->country,
            'birthday' => $request->birthday,
        ];
        $account->update(array_filter($attributes));
        $account->update($request->account);

        return ['message' => 'Updated Customer successfully'];
    }
}
