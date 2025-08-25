<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

// Enums
use App\Enums\LoginType;
use App\Enums\VerificationCodeType;

// Models
use App\Models\Account;
use App\Models\User;
use App\Models\VerificationCode;
use Starsnet\Project\Paraqon\App\Models\Notification;

class AuthenticationController extends Controller
{
    public function login(Request $request): array
    {
        // Declare local constants
        $loginType = strtoupper($request->input('type', LoginType::EMAIL->value));

        // Attempt to find User via Account Model
        $user = $this->findUserByCredentials($loginType, $request->email, $request->area_code, $request->phone);
        if (is_null($user)) abort(404, 'Credentials are not valid');

        // Check if too many failed login attempts
        /** @var ?Account $account */
        $account = $user->account;
        if (is_null($account)) abort(404, 'Account not found');
        if (isset($account->failed_login_count) && $account->failed_login_count >= 5) {
            abort(423, 'Too many failed attempts. Your account has been temporarily locked for security reasons');
        }

        // Get login_id from found user
        $credentials = ['login_id' => $user->login_id, 'password' => $request->password];
        if (!Auth::attempt($credentials)) {
            // For incorrect password, increment failed_login_count on account
            $newFailedLoginCount = $account->failed_login_count + 1;
            $account->update(['failed_login_count' => $newFailedLoginCount]);
            abort(404, 'Credentials are not valid');
        }

        // Get User, then validate
        $user = $this->user();
        if ($user->is_deleted === true) abort(403, 'Account not found.');
        if ($user->is_disabled === true) abort(403, 'Account is disabled.');
        if ($user->is_staff === true) abort(403, 'Account is a staff-only account.');
        $user->account;

        // Disable all old LOGIN type VerificationCode
        $user->verificationCodes()
            ->where('type', VerificationCodeType::LOGIN->value)
            ->where('is_used', false)
            ->update(['is_used' => true]);

        // Generate new VerificationCode
        $code = $this->generateVerificationCodeByType(VerificationCodeType::LOGIN->value, 15, $user, $loginType);

        // Clear failed login count
        $account->update(['failed_login_count' => 0]);

        // Check is_2fa_verification_required
        $is2faVerificationRequired = $account->is_2fa_verification_required ?? true;

        return [
            'message' => 'Login credentials are valid, we have sent you a 2FA verification code',
            'code' => $code,
            'is_2fa_verification_required' => $is2faVerificationRequired
        ];
    }

    public function twoFactorAuthenticationlogin(Request $request): array
    {
        $now = now();

        // Extract attributes from $request
        $loginType = strtoupper($request->input('type', LoginType::EMAIL->value));
        $code = $request->code;

        // Attempt to find User via Account Model
        $user = $this->findUserByCredentials($loginType, $request->email, $request->area_code, $request->phone);
        if (is_null($user)) abort(404, 'Credentials are not valid');

        // Get login_id from found user
        $credentials = ['login_id' => $user->login_id, 'password' => $request->password];
        if (!Auth::attempt($credentials)) abort(404, 'Credentials are not valid');

        // Get User, then validate
        $user = $this->user();
        if ($user->is_deleted === true) abort(403, 'Account not found.');
        if ($user->is_disabled === true) abort(403, 'Account is disabled.');
        if ($user->is_staff === true) abort(403, 'Account is a staff-only account.');
        $user->account;

        // Find VerificationCode
        $verificationCode = $user->verificationCodes()
            ->where('type', 'LOGIN')
            ->where('code', $code)
            ->orderBy('_id', 'desc')
            ->first();

        if (is_null($verificationCode)) abort(404, 'Invalid Verfication Code');
        if ($verificationCode->is_disabled === true) abort(403, 'Verfication Code is disabled');
        if ($verificationCode->is_used === true) abort(403, 'Verfication Code has already been used');
        if ($verificationCode->expires_at < $now) abort(403, 'VerificationCode is expired');

        // Update VerificationCode
        $verificationCode->update([
            'is_used' => true,
            'used_at' => $now
        ]);

        // Create token
        $accessToken = $user->createToken('customer')->accessToken;

        // Update last_logged_in_at
        $user->touchLastLoggedInAt();

        return [
            'token' => $accessToken,
            'user' => $user
        ];
    }

    public function migrateToRegistered(Request $request): array
    {
        $now = now();

        // Extract attributes from $request
        $loginType = $request->input('type');
        $password = $request->input('password', 'password');

        // Get User, then validate
        $user = $this->user();
        if ($user->type !== LoginType::TEMP->value) abort(401, 'User does not have permission.');

        // Find if user exists
        $ifAccountExists = Account::where('email', $request->email)->exists();
        if ($ifAccountExists) abort(401, 'This email address has already been taken: ' . $request->email);

        $ifAccountExists = Account::where('area_code', $request->area_code)->where('phone', $request->phone)->exists();
        if ($ifAccountExists) abort(401, 'This phone has already been taken: +' . $request->area_code . ' ' . $request->phone);

        $loginID = $request->email;
        if ($loginType == LoginType::PHONE->value) {
            $loginID = $request->area_code . $request->phone;
        }

        // Update User
        $user->update([
            'login_id' => $loginID,
            'type' => $loginType,
            'password' => Hash::make($password),
            'password_updated_at' => now()
        ]);

        // Update Account
        /** @var ?Account $account */
        $account = $user->account;

        // Formulate client_no
        $code = $now->format('ym');
        $existingAccounts = Account::where('client_no', 'regex', '/CT' . $code . '/i')
            ->orderBy('client_no', 'desc')
            ->pluck('client_no')
            ->all();
        $nextSequence = empty($existingAccounts)
            ? 1
            : ((int) substr($existingAccounts[0], -4)) + 1;
        $nextClientCode = 'CT' . $code . str_pad($nextSequence, 4, '0', STR_PAD_LEFT);

        $accountUpdateAttributes = [
            'username' => $request->username,
            'avatar' => $request->avatar,
            'gender' => $request->gender,
            'country' => $request->country,

            'email' => $request->email,
            'area_code' => $request->area_code,
            'phone' => $request->phone,
            'source' => $request->source,

            // New key for Account Type
            'account_type' => $request->input('account_type', "INDIVIDUAL"),
            'company_name' => $request->input('company_name'),
            'business_registration_number' => $request->input('business_registration_number'),
            'company_address' => $request->input('company_address'),
            'business_registration_verification' => $request->input('business_registration_verification'),
            'registrar_of_shareholders_verification' => $request->input('registrar_of_shareholders_verification'),

            // Account Verification
            'address_proof_verification' => $request->input('address_proof_verification'),
            'photo_id_verification' => $request->input('photo_id_verification'),
            'legal_name_verification' => $request->input('legal_name_verification'),
            'first_name' => $request->input('first_name'),
            'last_name' => $request->input('last_name'),

            // Boolean
            'is_2fa_verification_required' => $request->input('is_2fa_verification_required'),

            // Admin Created Accounts
            'is_created_by_admin' => $request->input('is_created_by_admin', false),
            'is_default_password_changed' => $request->input('is_default_password_changed', false),

            'client_no' => $nextClientCode
        ];
        $account->update($accountUpdateAttributes);

        // Update Notification Settings
        $setting = $account->notificationSetting;

        $setting->update([
            "channels" => ["EMAIL"],
            "language" => "EN",
            "is_accept" => [
                "marketing_info" => true,
                "delivery_update" => true,
                "wishlist_product_update" => true,
                "special_offers" => true,
                "auction_notifications" => true,
                "bid_notifications" => true,
                "monthly_newsletter" => true,
                "sales_support" => true
            ],
            "is_notifiable" => true,
        ]);

        return [
            'message' => 'Registered as new Customer successfully',
            'id' => $user->id,
            'warehouse_id' => null
        ];
    }

    public function changeEmailRequest(): array
    {
        // Get User, then validate
        $user = $this->user();
        if ($user->is_disabled === true) abort(403, 'User is disabled.');

        // Get Account, then validate
        $account = $this->account();
        if (is_null($account)) abort(404, 'Account not found.');

        // Get email (address), then validate
        /** @var ?string $email */
        $email = $account->email;
        if (is_null($email)) abort(404, 'Email not found.');

        // Generate new VerificationCode
        $this->generateVerificationCodeByType(VerificationCodeType::CHANGE_EMAIL->value, 15, $user, 'EMAIL');

        return ['message' => 'Email sent to ' . $email];
    }

    public function changePhoneRequest(): array
    {
        // Get User, then validate
        $user = $this->user();
        if ($user->is_disabled === true) abort(403, 'User is disabled.');

        // Get Account, then validate
        $account = $this->account();
        if (is_null($account)) abort(404, 'Account not found.');

        // Get phone info, then validate
        $areaCode = $account->area_code;
        $phone = $account->phone;
        if (is_null($areaCode) || is_null($phone)) abort(404, 'Phone not found');

        // Generate new VerificationCode
        $this->generateVerificationCodeByType(VerificationCodeType::CHANGE_PHONE->value, 15, $user, 'PHONE');

        return ['message' => 'SMS sent to +' . $areaCode . ' ' . $phone];
    }

    public function changePhone(Request $request): array
    {
        $now = now();

        // Extract attributes from $request
        $verificationCode = $request->input('verification_code');
        $areaCode = $request->input('area_code');
        $phone = $request->input('phone');

        // Get VerificationCode, then validate
        $code = VerificationCode::where('code', $verificationCode)
            ->where('type', VerificationCodeType::CHANGE_PHONE->value)
            ->latest()
            ->first();

        if (is_null($code)) abort(404, 'VerificationCode not found.');
        if ($code->is_disabled === true) abort(403, 'VerificationCode is disabled.');
        if ($code->is_used === true) abort(403, 'VerificationCode is already used.');
        if ($code->expires_at < $now) abort(403, 'VerificationCode is expired.');

        // Get User, then validate
        /** @var ?User $user */
        $user = $code->user;
        if (is_null($user)) abort(404, 'User not found.');
        if ($user->is_disabled === true) abort(403, 'Account is disabled.');

        // Validate if new credentials are duplicated
        $newLoginID = $areaCode . $phone;
        $isLoginIDtaken = User::where('login_id', $newLoginID)->exists();
        if ($isLoginIDtaken) abort(403, 'Phone is already taken.');

        // Update User
        $user->update(['login_id' => $newLoginID]);

        // Update Account
        $account = $user->account;
        $account->update([
            'area_code' => $areaCode,
            'phone' => $phone
        ]);

        // Update VerificationCode
        $code->update([
            'is_used' => true,
            'used_at' => $now
        ]);

        return [
            'message' => 'Updated phone successfully',
        ];
    }

    public function forgetPassword(Request $request): array
    {
        // Extract attributes from $request
        $loginType = strtoupper($request->input('type', LoginType::EMAIL->value));

        // Validate Request
        if (!in_array($loginType, [LoginType::EMAIL->value, LoginType::PHONE->value])) abort(422, 'Incorrect type');
        if ($loginType == LoginType::EMAIL->value && is_null($request->email)) abort(401, 'Missing email');
        if ($loginType == LoginType::PHONE->value && is_null($request->area_code) && is_null($request->phone))  abort(401, 'Missing area_code or phone');

        // Attempt to find User via Account Model
        $user = $this->findUserByCredentials($loginType, $request->email, $request->area_code, $request->phone,);
        if (is_null($user)) abort(404, 'Account not found');
        if ($user->is_disabled === true) abort(403, 'Account is disabled.');

        // Create VerificationCode
        $this->generateVerificationCodeByType(VerificationCodeType::FORGET_PASSWORD->value, 30, $user, $loginType);

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

    private function findUserIDByCredentials(string $loginType, ?string $email, ?string $areaCode, ?string $phone): ?string
    {
        switch ($loginType) {
            case LoginType::EMAIL->value:
            case LoginType::TEMP->value:
                $account = Account::where('email', $email)->first();
                return optional($account)->user_id;
            case LoginType::PHONE->value:
                $account = Account::where('area_code', $areaCode)
                    ->where('phone', $phone)
                    ->first();
                return optional($account)->user_id;
            default:
                return null;
        }
    }

    private function findUserByCredentials(string $loginType, ?string $email, ?string $areaCode, ?string $phone): ?User
    {
        $userID = $this->findUserIDByCredentials($loginType, $email, $areaCode, $phone);
        return User::find($userID);
    }

    private function generateVerificationCodeByType(string $codeType, int $minutesAllowed = 15, User $user, ?string $notificationType = 'EMAIL')
    {
        $code = (string) random_int(100000, 999999);

        $user->verificationCodes()->create([
            'type' => $codeType,
            'code' => $code,
            'expires_at' => now()->addMinutes($minutesAllowed),
            'notification_type' => $notificationType
        ]);

        return $code;
    }

    public function getAuthUserInfo(): array
    {
        // Get User, then validate
        $user = $this->user();
        if (is_null($user)) abort(404, 'User not found');
        if ($user->is_disabled === true) abort(403, 'Account is disabled.');

        // Get Role 
        $user->role = $user->getRole();

        // Get Unread Message Count
        $account = $this->account();
        $unreadNotificationCount = Notification::where('account_id', $account->_id)
            ->where('is_read', false)
            ->count();
        $user->unread_notification_count = $unreadNotificationCount;

        return ['user' => $user];
    }
}
