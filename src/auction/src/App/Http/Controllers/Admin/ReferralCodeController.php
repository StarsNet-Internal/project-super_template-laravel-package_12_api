<?php

namespace Starsnet\Project\Auction\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// Models
use App\Models\User;
use StarsNet\Project\Auction\App\Models\ReferralCode;
use StarsNet\Project\Auction\App\Models\ReferralCodeHistory;

class ReferralCodeController extends Controller
{
    public function massGenerateReferralCodes(Request $request): array
    {
        $quotaLeft = (int) $request->input('quota_left', 3);

        $validCustomerIDs = User::where('type', '!=', 'TEMP')
            ->where('is_staff', false)
            ->with(['account.customer'])
            ->get()
            ->pluck('account.customer.id')
            ->filter() // Remove null values
            ->unique() // Ensure no duplicates
            ->values() // Reset keys
            ->all();

        $createdCount = 0;
        $existingCodes = ReferralCode::pluck('code')->toArray();

        foreach ($validCustomerIDs as $customerID) {
            do {
                $code = $this->generateUniqueCode(8, $existingCodes);
            } while (in_array($code, $existingCodes));

            ReferralCode::create([
                'customer_id' => $customerID,
                'code' => $code,
                'quota_left' => $quotaLeft
            ]);

            $existingCodes[] = $code; // Add to existing codes to prevent duplicates
            $createdCount++;
        }

        return ['message' => 'Created a total of ' . $createdCount . ' new referral codes'];
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
