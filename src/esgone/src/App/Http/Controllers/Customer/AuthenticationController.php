<?php

namespace Starsnet\Project\Esgone\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

// Enums
use App\Enums\VerificationCodeType;
use App\Enums\LoginType;

// Models
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Role;
use App\Models\User;

class AuthenticationController extends Controller
{
    public function register(Request $request): array
    {
        $now = now();

        // Validate CustomerGroup first
        $categoryIds = (array) $request->input('category_ids');

        // Prepend the green member ID
        $greenMemberId = CustomerGroup::where('slug', 'website-members')->value('id');
        $categoryIds[] = $greenMemberId;

        // Get all categories in one query (with existence check)
        $categories = CustomerGroup::whereIn('_id', $categoryIds)->get();
        if ($categories->count() !== count($categoryIds)) {
            abort(404, 'One or more CustomerGroups not found');
        }

        // Extract attributes from $request
        $email = $request->email;
        $password = $request->password ?? 'password';

        /** @var ?Role $role */
        $role = Role::where('slug', 'customer')->first();
        if (is_null($role)) abort(404, 'Customer Role not found');

        // Create User
        /** @var User $user */
        $user = User::create([
            'type' => LoginType::EMAIL->value,
            'login_id' => $email,
            'password' => Hash::make($password),
            'is_staff' => true,
            'password_updated_at' => $now
        ]);

        // Create Account
        /** @var Account $account */
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
        /** @var Customer $customer  */
        $customer = Customer::create([
            'account_id' => $account->id,
            'delivery_recipient' => [
                'name' => $account->username,
                'address' => $request->address,
                'area_code' => $account->area_code,
                'phone' => $account->phone,
            ]
        ]);

        // Create VerificationCode
        $user->verificationCodes()->create([
            'type' => VerificationCodeType::ACCOUNT_VERIFICATION->value,
            'code' => str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT),
            'expires_at' => now()->addMinutes(60)
        ]);

        // Attach groups and update account
        $customer->attachGroups($categories);
        $account->update($request->except([
            'type',
            'username',
            'email',
            'password',
            'category_ids'
        ]));

        return ['message' => 'Registered as new Customer successfully'];
    }
}
