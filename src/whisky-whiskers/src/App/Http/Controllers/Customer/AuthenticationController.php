<?php

namespace Starsnet\Project\WhiskyWhiskers\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// Enums
use App\Enums\LoginType;
use App\Enums\VerificationCodeType;

// Models
use App\Models\User;
use App\Models\Account;
use App\Models\Warehouse;

class AuthenticationController extends Controller
{
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

    public function migrateToRegistered(Request $request): array
    {
        // Extract attributes from $request
        $loginType = $request->input('type');
        $password = $request->input('password', 'password');

        $user = $this->user();
        if ($user->type !== LoginType::TEMP->value) abort(401, 'This User does not have permission');

        // Validate loginID
        $loginID = $request->email;
        if ($loginType == LoginType::PHONE->value) {
            $loginID = $request->area_code . $request->phone;
        }

        $isLoginIDTaken = User::where('login_id', $loginID)->exists();
        if ($isLoginIDTaken) abort(403, 'login_id is already taken.');

        // Generate Code
        $this->generateVerificationCodeByType(
            VerificationCodeType::ACCOUNT_VERIFICATION->value,
            60,
            $user
        );

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
        $account->update([
            'username' => $request->username,
            'avatar' => $request->avatar,
            'gender' => $request->gender,
            'country' => $request->country,
            'email' => $request->email,
            'area_code' => $request->area_code,
            'phone' => $request->phone,
        ]);

        // Create Warehouse
        $warehouseTitle = 'account_warehouse_' . $account->_id;
        $warehouse = Warehouse::create([
            'type' => 'PERSONAL',
            'slug' => Str::slug($warehouseTitle),
            'title' => [
                'en' => $warehouseTitle,
                'zh' => $warehouseTitle,
                'cn' => $warehouseTitle
            ],
            'account_id' => $account->_id,
            'is_system' => true,
        ]);

        return [
            'message' => 'Registered as new Customer successfully',
            'id' => $user->id,
            'warehouse_id' => $warehouse->_id
        ];
    }
}
