<?php

namespace StarsNet\Project\Easeca\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Traits
use StarsNet\Project\Easeca\App\Http\Controllers\Traits\ProjectAuthenticationTrait;

// Enums
use App\Enums\LoginType;

// Models
use App\Models\Account;
use App\Models\Customer;
use App\Models\NotificationSetting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class CustomerController extends Controller
{
    use ProjectAuthenticationTrait;

    public function getAllCustomers()
    {
        $customers = Customer::where('is_deleted', false)
            ->with(['account'])
            ->get();

        $customers = array_map(function ($customer) {
            $customer['user'] = [
                'username' => $customer['account']['username'],
                'avatar' => $customer['account']['avatar'],
            ];
            $customer['country'] = $customer['account']['country'];
            $customer['gender'] = $customer['account']['gender'];
            $customer['email'] = $customer['account']['email'];
            $customer['area_code'] = $customer['account']['area_code'];
            $customer['phone'] = $customer['account']['phone'];
            $customer['store_id'] = $customer['account']['store_id'] ?? null;
            $customer['is_approved'] = $customer['account']['is_approved'] ?? null;

            unset($customer['account']);
            return $customer;
        }, $customers->toArray());

        return $customers;
    }

    public function getCustomerDetails(Request $request)
    {
        $customer = Customer::with(['account', 'account.user'])
            ->find($request->route('id'))
            ->append(['order_statistics']);
        if (is_null($customer)) abort(404, 'Customer not found');

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
        $customer['store_id'] = $customer['account']['store_id'] ?? null;
        $customer['is_approved'] = $customer['account']['is_approved'] ?? null;
        unset($customer['account']);

        return $customer;
    }

    public function deleteCustomers(Request $request)
    {
        /** @var Collection $customers */
        $customers = Customer::whereIn('_id', $request->input('ids'));

        /** @var Customer $customer */
        foreach ($customers as $customer) {
            $user = $customer->getUser();
            $user->update([
                'is_deleted' => true,
                'deleted_at' => now()
            ]);
            $this->updateLoginIdOnDelete($user);
        }

        return [
            'message' => 'Deleted ' . $customers->count() . ' Customer(s) successfully'
        ];
    }

    public function createCustomer(Request $request)
    {
        // Extract attributes from $request
        $loginType = $request->input('type');
        $email = $request->input('email');
        $areaCode = $request->input('area_code');
        $phone = $request->input('phone');
        $password = $request->input('password', 'password');

        // Create User
        $loginID = $email;
        if ($loginType == LoginType::PHONE->value) $loginID = $areaCode . $phone;
        $isLoginIDTaken = User::where('login_id', $loginID)->exists();
        if ($isLoginIDTaken) abort(403, "Fail to create Customer, login_id already exists: $loginID");

        /** @var Role $role */
        $role = Role::where('slug', 'customer')->first();
        if (is_null($role)) abort(404, 'Role not found');

        // Create User
        $user = User::create([
            'login_id' => $loginID,
            'type' => $loginType,
            'is_staff' => false,
            'is_verified' => true,
            'password' => Hash::make($password),
            'verified_at' => now(),
            'password_updated_at' => now()
        ]);

        // Create Account
        $account = Account::create([
            'username' => $request->username,
            'email' => $email,
            'area_code' => $areaCode,
            'phone' => $phone,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'store_id' => $request->store_id,
            'is_approved' => true,
        ]);

        // Create Customer
        Customer::create([
            'account_id' => $account->id,
            'delivery_recipient' => [
                'name' => $request->username,
                'address' => $request->address,
                'area_code' => $areaCode,
                'phone' => $phone,
            ]
        ]);

        // Create NotificationSetting
        NotificationSetting::create([
            'account_id' => $account->id,
            'is_accept' => [
                'marketing_info' => false,
                'delivery_update' => false,
                'wishlist_product_update' => false
            ]
        ]);

        return [
            'message' => 'Created New Customer successfully',
            '_id' => $user->id,
            'customer_id' => optional($account->customer)->_id
        ];
    }

    public function approveCustomerAccounts(Request $request): array
    {
        $customers = Customer::whereIn('_id', (array) $request->input('ids'));

        // Update customer(s)
        foreach ($customers as $customer) {
            $customer->account->update(['is_approved' => true]);
        }

        return [
            'message' => 'Approved ' . $customers->count() . ' Account(s) successfully'
        ];
    }

    public function updateAssignedStore(Request $request): array
    {
        $customer = Customer::find($request->route('id'));
        $account = $customer->account;
        $account->update([
            'store_id' => $request->store_id,
        ]);

        return [
            'message' => 'Assigned to New Merchant successfully'
        ];
    }
}
