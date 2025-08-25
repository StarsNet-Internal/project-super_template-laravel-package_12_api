<?php

namespace Starsnet\Project\Esgone\App\Http\Controllers\Admin;

// Laravel built-in
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

// Enums
use App\Enums\LoginType;
use App\Enums\VerificationCodeType;

// Models
use App\Models\Account;
use App\Models\Alias;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Models\VerificationCode;

use App\Http\Controllers\Customer\AuthenticationController as CustomerAuthenticationController;
use App\Models\CustomerGroup;
use App\Models\NotificationSetting;
use App\Models\Role;

class AuthenticationController extends CustomerAuthenticationController
{
    protected function generatePhoneVerificationCodeByType(User $user, string $type, int $minutesAllowed = 60): VerificationCode
    {
        return $user->verificationCodes()->create([
            'type' => $type,
            'code' => (string) random_int(100000, 999999),
            'expires_at' => now()->addMinutes($minutesAllowed)
        ]);
    }

    public function login(Request $request): array
    {
        // Login
        $data = parent::login($request);

        // Append Store data
        $account = $this->account();
        if (is_string($account->store_id)) {
            /** @var ?Store $store */
            $store = Store::find($account->store_id) ?? Store::find(Alias::getValue($account->store_id));
            $data['user']['account']['country'] = $store?->is_system === true
                ? 'default-main-store'
                : $store?->remarks;
        }

        // Update Account token
        if (!empty($request->fcm_token)) {
            /** @var string[] $tokens */
            $tokens = $account->fcm_tokens ?? [];
            array_push($tokens, $request->fcm_token);
            $account->update(['fcm_tokens' => $tokens]);
        }

        return $data;
    }

    public function logoutMobileDevice(Request $request): array
    {
        $tokenToRemove = $request->input('fcm_token');

        if (is_string($tokenToRemove) && $tokenToRemove !== '') {
            $this->account()->update([
                'fcm_tokens' => array_values(
                    array_filter(
                        $this->account()->fcm_tokens ?? [],
                        fn($token) => $token !== $tokenToRemove
                    )
                )
            ]);
        }

        return parent::logout();
    }

    public function getAuthUserInfo(): array
    {
        // Get Auth User Info
        $data = parent::getAuthUserInfo();

        // Append Store data
        $account = $this->account();
        if (is_string($account->store_id)) {
            /** @var ?Store $store */
            $store = Store::find($account->store_id) ?? Store::find(Alias::getValue($account->store_id));
            $data['user']['account']['country'] = $store?->is_system === true
                ? 'default-main-store'
                : $store?->remarks;
        }

        return $data;
    }

    public function register(Request $request): array
    {
        $now = now();

        // Extract attributes from $request
        $email = $request->email;
        $password = $request->password ?? 'password';
        if (is_null($email)) abort(400, 'Email not found');

        /** @var ?Role $role */
        $role = Role::where('slug', 'staff')->first();
        if (is_null($role)) abort(404, 'Staff Role not found');

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
        /** @var ?Store $store */
        $store = Store::find($request->store_id) ?? Store::find(Alias::getValue($request->store_id));
        /** @var Account $account */
        $account = Account::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'username' => $request->username ?? substr($email, 0, strrpos($email, '@')),
            'avatar' => $request->avatar,
            'email' => $email,
            'area_code' => $request->area_code,
            'phone' => $request->phone,
            'store_id' => $request->store_id,
            'is_approved' => (bool) optional($store)->is_system,
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
        /** @var ?CustomerGroup $customer */
        $allCustomerGroup = CustomerGroup::where('slug', 'all-customers')->first();
        if (!is_null($allCustomerGroup)) {
            $customer->attachGroups(collect($allCustomerGroup));
        }

        // Create NotificationSetting
        NotificationSetting::create([
            'account_id' => $account->id,
        ]);

        // Create VerificationCode
        $this->generatePhoneVerificationCodeByType(
            $user,
            VerificationCodeType::ACCOUNT_VERIFICATION->value,
            60
        );

        return ['message' => 'Registered as new Customer successfully'];
    }

    public function forgetPassword(Request $request): array
    {
        // Extract attributes from $request
        $loginType = $request->input('type', LoginType::EMAIL->value);

        // Get loginID from $request, then validate
        $loginID = null;
        switch ($loginType) {
            case LoginType::EMAIL->value:
                $loginID = $request->email;
                break;
            case LoginType::PHONE->value:
                $loginID = $request->area_code . $request->phone;
                break;
            default:
                abort(401, 'Incorrect type input');
                break;
        }
        if (is_null($loginID)) abort(404, 'loginID not found');

        // Get User, then validate
        $user = User::where('type', $loginType)
            ->where('login_id', $loginID)
            ->latest()
            ->first();
        if (is_null($user)) abort(404, 'User not found');
        if ($user->is_disabled === true) abort(403, 'This account is disabled');

        // Create VerificationCode
        $this->generatePhoneVerificationCodeByType(
            $user,
            VerificationCodeType::FORGET_PASSWORD->value,
            30
        );

        // Get Account, then validate
        /** @var ?Account $account */
        $account = $user->account;
        if (is_null($account)) abort(404, 'Account not found');

        switch ($loginType) {
            case LoginType::EMAIL->value:
                $email = $account->email;
                if (is_null($email)) abort(404, 'Email not found');
                return ['message' => 'Email sent to ' . $email];
            case LoginType::PHONE->value:
                $areaCode = $account->area_code;
                $phone = $account->phone;
                if (is_null($areaCode) || is_null($phone)) abort(404, 'Phone not found');
                return ['message' => 'SMS sent to +' . $areaCode . ' ' . $phone];
            default:
                abort(401, 'Incorrect type input');
                return ['message' => 'Incorrect type input'];
        }
    }

    public function getVerificationCode(): array
    {
        $this->generatePhoneVerificationCodeByType(
            $this->user(),
            VerificationCodeType::ACCOUNT_VERIFICATION->value,
            60
        );

        return ['message' => 'Generated new VerificationCode successfully'];
    }
}
