<?php

namespace Starsnet\Project\WhiskyWhiskersUpgrade\App\Http\Controllers\Customer;

use App\Enums\LoginType;
use App\Http\Controllers\Controller;
use App\Models\Account;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthenticationController extends Controller
{
    public function migrateToRegistered(Request $request)
    {
        // Extract attributes from $request
        $loginType = $request->input('type', LoginType::EMAIL->value);
        $password = $request->input('password', 'password');

        // Get User, then validate
        $user = $this->user();
        if ($user->type !== LoginType::TEMP->value) abort(401, 'User does not have permission.');

        // Find if user exists
        $ifAccountExists = Account::where('email', $request->email)->exists();
        if ($ifAccountExists) abort(401, 'This email address has already been taken: ' . $request->email);

        $ifAccountExists = Account::where('area_code', $request->area_code)->where('phone', $request->phone)->exists();
        if ($ifAccountExists) abort(401, 'This phone has already been taken: +' . $request->area_code . ' ' . $request->phone);

        // Update User
        $loginID = $request->email;
        if ($loginType == LoginType::PHONE->value) {
            $loginID = $request->area_code . $request->phone;
        }

        $user->update([
            'login_id' => $loginID,
            'type' => $loginType,
            'password' => Hash::make($password),
            'password_updated_at' => now()
        ]);

        // Update Account
        /** @var ?Account $account */
        $account = $user->account;
        $accountUpdateAttributes = [
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

            // Admin Created Accounts
            'is_created_by_admin' => $request->input('is_created_by_admin', false),
            'is_default_password_changed' => $request->input('is_default_password_changed', false)
        ];
        $account->update($accountUpdateAttributes);

        // Update Notification Settings
        $setting = $account->notificationSetting;
        $notificationChannels = ["EMAIL"];

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

        return [
            'message' => 'Registered as new Customer successfully',
            'id' => $user->id,
            'warehouse_id' => null
        ];
    }
}
