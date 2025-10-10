<?php

namespace Starsnet\Project\Rmhc\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

// Enums
use App\Enums\LoginType;

// Models
use App\Models\Account;

class AuthenticationController extends Controller
{
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
            'channels' => ['EMAIL', 'SMS'],
            'language' => 'EN',
            'is_accept' => [
                'marketing_info' => true,
                'delivery_update' => true,
                'wishlist_product_update' => true,
                'special_offers' => true,
                'auction_notifications' => true,
                'bid_notifications' => true,
                'monthly_newsletter' => true,
                'sales_support' => true
            ],
            'is_notifiable' => true,
        ]);

        return [
            'message' => 'Registered as new Customer successfully',
            'id' => $user->id,
            'warehouse_id' => null
        ];
    }
}
