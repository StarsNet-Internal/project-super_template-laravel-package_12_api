<?php

namespace StarsNet\Project\Easeca\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Customer\AuthenticationController as CustomerAuthenticationController;
use Illuminate\Http\Request;

// Traits
use StarsNet\Project\Easeca\App\Http\Controllers\Traits\ProjectAuthenticationTrait;

// Enums
use App\Enums\LoginType;
use App\Enums\VerificationCodeType;

// Models
use App\Models\Account;
use App\Models\Alias;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\AccessToken;

class AuthenticationController extends CustomerAuthenticationController
{
    use ProjectAuthenticationTrait;

    public function generatePhoneVerificationCodeByType(User $user, string $type, int $minutesAllowed = 60): VerificationCode
    {
        $code = (string) random_int(100000, 999999);

        return $user->verificationCodes()->create([
            'type' => $type,
            'code' => $code,
            'expires_at' => now()->addMinutes($minutesAllowed)
        ]);

        return $code;
    }

    public function login(Request $request): array
    {
        $data = parent::login($request);

        $account = $this->account();
        if ($account->store_id != null) {
            $store = Store::find($account->store_id)
                ?? Store::find(Alias::getValue($account->store_id));
            $data['user']['account']['country'] =
                $store->is_system === true ? 'default-main-store' : $store->remarks;
        }

        if (isset($request->fcm_token) && $request->fcm_token != '') {
            $tokens = $account->fcm_tokens ?? [];
            array_push($tokens, $request->fcm_token);
            $account->update([
                'fcm_tokens' => $tokens
            ]);
        }

        return $data;
    }

    public function logoutMobileDevice(Request $request)
    {
        if (isset($request->fcm_token) && $request->fcm_token != '') {
            $tokenToRemoved = $request->fcm_token;
            $account = $this->account();
            $tokens = array_filter($account->fcm_tokens, function ($token) use ($tokenToRemoved) {
                return $token != $tokenToRemoved;
            });
            $account->update([
                'fcm_tokens' => array_values($tokens)
            ]);
        }

        return parent::logout();
    }

    public function getAuthUserInfo(): array
    {
        $data = parent::getAuthUserInfo();

        $account = $this->account();
        if ($account->store_id != null) {
            $store = Store::find($account->store_id)
                ?? Store::find(Alias::getValue($account->store_id));
            $data['user']['account']['country'] =
                $store->is_system === true ? 'default-main-store' : $store->remarks;
        }

        return $data;
    }

    public function register(Request $request): array
    {
        // Extract attributes from $request
        $email = $request->email;
        $password = $request->password ?? 'password';

        $now = now();

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


        // Update User
        $this->updateUserViaRegistration($user, $request);
        $this->generatePhoneVerificationCodeByType(
            $user,
            VerificationCodeType::ACCOUNT_VERIFICATION->value,
            60
        );

        // Update Account
        $account = $user->account;
        $this->updateAccountViaRegistration($account, $request);

        // Package
        $customer = $account->customer;
        $store = Store::find($request->store_id)
            ?? Store::find(Alias::getValue($request->store_id));

        $account->update([
            'store_id' => $request->store_id,
            'is_approved' => $store->is_system === true ? true : false,
        ]);
        $customer->update([
            'delivery_recipient' => [
                'name' => $request->username,
                'address' => $request->address,
                'area_code' => $request->area_code,
                'phone' => $request->phone,
            ]
        ]);

        return [
            'message' => 'Registered as new Customer successfully',
        ];
    }

    public function forgetPassword(Request $request)
    {
        // Extract attributes from $request
        $loginType = $request->input('type', LoginType::EMAIL);

        // Get loginID from $request, then validate
        switch ($loginType) {
            case LoginType::EMAIL:
                $loginID = $request->email;
                break;
            case LoginType::PHONE:
                $loginID = $request->area_code . $request->phone;
                break;
            default:
                return response()->json([
                    'message' => 'Incorrect type input'
                ], 401);
        }

        if (is_null($loginID)) {
            return response()->json([
                'message' => 'login_id not found'
            ], 404);
        }

        // Get User, then validate
        $user = (new User)->getUserByTypeAndLoginID($loginType, $loginID);

        if (is_null($user)) {
            return response()->json([
                'message' => 'User not found'
            ], 404);
        }

        if ($user->is_disabled === true) {
            return response()->json([
                'message' => 'This account is disabled'
            ], 403);
        }

        // Create VerificationCode
        $verificationCode = $this->generatePhoneVerificationCodeByType(
            $user,
            VerificationCodeType::FORGET_PASSWORD->value,
            30
        );

        // Get Account, then validate
        /** @var ?Account $account */
        $account = $user->account;

        if (is_null($account)) {
            return response()->json([
                'message' => 'Account not found'
            ], 404);
        }

        switch ($loginType) {
            case LoginType::EMAIL:
                // Get email (address), then validate
                $email = $account->email;

                if (is_null($email)) {
                    return response()->json([
                        'message' => 'Email not found'
                    ], 404);
                }

                // TODO: Send email

                // Return success message
                return response()->json([
                    'message' => 'Email sent to ' . $email,
                ], 200);
            case LoginType::PHONE:
                // Get phone info, then validate
                $areaCode = $account->area_code;
                $phone = $account->phone;

                if (is_null($areaCode) || is_null($phone)) {
                    return response()->json([
                        'message' => 'Phone not found'
                    ], 404);
                }

                // TODO: Send SMS

                // Return success message
                return response()->json([
                    'message' => 'SMS sent to +' . $areaCode . ' ' . $phone,
                ], 200);
            default:
                // Default error message
                return response()->json([
                    'message' => 'Incorrect type input'
                ], 401);
        }
    }

    public function getVerificationCode(): array
    {
        $this->generatePhoneVerificationCodeByType(
            $this->user(),
            VerificationCodeType::ACCOUNT_VERIFICATION->value,
            60
        );

        return [
            'message' => 'Generated new VerificationCode successfully',
        ];
    }

    public function deleteAccount(): array
    {
        // Get User, then validate
        $user = $this->user();
        if (is_null($user) || $user->is_deleted === true) abort(404, 'User not found');

        // Delete User
        $user->update([
            'is_deleted' => true,
            'deleted_at' => now()
        ]);
        $this->updateLoginIdOnDelete($user);

        // Logout
        $token = $user->token();
        if ($token instanceof AccessToken) {
            $token->revoke();
        }

        return [
            'message' => 'Account scheduled to be deleted successfully'
        ];
    }
}
