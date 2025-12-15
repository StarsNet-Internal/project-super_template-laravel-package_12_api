<?php

namespace Starsnet\Project\Auction\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Carbon\Carbon;

// Enums
use App\Enums\LoginType;

// Models 
use App\Models\Account;
use Illuminate\Support\Facades\Hash;
use Starsnet\Project\Auction\App\Models\ReferralCode;
use Starsnet\Project\Auction\App\Models\ReferralCodeHistory;

class AuthenticationController extends Controller
{
    public function migrateToRegistered(Request $request)
    {
        // Validate if referral_code is filled in
        $now = now()->addHours(8);
        $user = $this->user();
        $customer = $this->customer();

        $inputCode = $request->referral_code;
        if (!is_null($inputCode)) {
            $cutoffDate = Carbon::create(2025, 8, 31)->endOfDay();
            if ($now->gt($cutoffDate)) abort(400, 'referral_code expired');

            $referralCodeDetails = ReferralCode::where('code', $inputCode)->latest()->first();
            if (is_null($referralCodeDetails)) abort(404, 'Invalid referral_code of ' . $inputCode);
            if ($referralCodeDetails->is_deleted === true) abort(403, 'Invalid referral_code of ' . $inputCode);
            if ($referralCodeDetails->is_disabled === true) abort(403, 'referral_code of ' . $inputCode . ' is disabled');

            $quotaLeft = $referralCodeDetails->quota_left;
            if ($quotaLeft <= 0) abort(403, 'Referral code has no more quota');
            if ($referralCodeDetails->customer_id === $customer->id) abort(400, 'You cannot use your own referral_code');
        }

        // Get User, then validate
        if ($user->type !== LoginType::TEMP->value)  abort(401, 'This User does not have permission');

        // Find if user exists
        $ifAccountExists = Account::where('email', $request->email)->exists();
        if ($ifAccountExists) abort(401, 'This email address has already been taken: ' . $request->email);

        $ifAccountExists = Account::where('area_code', $request->area_code)->where('phone', $request->phone)->exists();
        if ($ifAccountExists) abort(401, 'This phone has already been taken: +' . $request->area_code . ' ' . $request->phone);

        // Override request value
        $request->merge([
            'type' => LoginType::EMAIL->value,
        ]);

        // Update User
        $loginID = $request->email;
        $user->update([
            'login_id' => $loginID,
            'type' => LoginType::EMAIL->value,
            'password' => Hash::make($request->password),
            'password_updated_at' => now()
        ]);
      
        // Update Account
        /** @var ?Account $account */
        $account = $user->account;

        // Update User, then update Account
        $userUpdateAttributes = [
            'login_id' => $request->email
        ];
        $user->update($userUpdateAttributes);

        $accountUpdateAttributes = [
            'username' => $request->username ?? 'Guest',
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
            'is_default_password_changed' => $request->input('is_default_password_changed', false)
        ];
        $account->update($accountUpdateAttributes);

        // Update Notification Settings
        $setting = $account->notificationSetting;

        $notificationChannels = ["EMAIL", "SMS"];
        switch ($request->area_code) {
            case '852':
            case '86':
            default: {
                    $notificationChannels = ["EMAIL"];
                    break;
                }
        }

        $setting->update([
            "channels" => $notificationChannels,
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

        // Update referral_code logic
        $referralCodeDetails = ReferralCode::where('code', $inputCode)->latest()->first();
        if (!is_null($referralCodeDetails)) {
            // Deduct 1 quota
            $referralCodeDetails->update(['quota_left' => $referralCodeDetails->quota_left - 1]);
            // Create new ReferralCodeHistory
            ReferralCodeHistory::create([
                'owned_by_customer_id' => $referralCodeDetails->customer_id,
                'used_by_customer_id' => $customer->id,
                'referral_code_id' => $referralCodeDetails->id,
                'code' => $referralCodeDetails->code,
            ]);
        }

        // Create ReferralCode
        $existingCodes = ReferralCode::pluck('code')->toArray();
        do {
            $code = $this->generateUniqueCode(8, $existingCodes);
        } while (in_array($code, $existingCodes));
        ReferralCode::create([
            'customer_id' => $customer->id,
            'code' => $code,
            'quota_left' => 3
        ]);

        return [
            'message' => 'Registered as new Customer successfully',
            'id' => $user->id,
            'warehouse_id' => null
        ];
    }

    protected function generateUniqueCode(int $length, array $existingCodes): string
    {
        $characters = '123456789ABCDEFGHIJKLMNPQRSTUVWXYZ'; // No zero
        $max = strlen($characters) - 1;
        $code = '';

        do {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $characters[random_int(0, $max)];
            }
        } while (in_array($code, $existingCodes));

        return $code;
    }
}
