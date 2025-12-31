<?php

namespace Starsnet\Project\TcgBidGame\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Models
use App\Models\Customer;
use Starsnet\Project\TcgBidGame\App\Models\CoinPackage;
use Starsnet\Project\TcgBidGame\App\Models\CoinPackagePurchase;
use Starsnet\Project\TcgBidGame\App\Models\GameUser;

class CoinPackageController extends Controller
{
    public function getAllCoinPackages()
    {
        $packages = CoinPackage::active()
            ->ordered()
            ->get();

        return $packages;
    }

    public function purchaseCoinPackage(Request $request)
    {
        $packageId = $request->route('package_id');
        $package = CoinPackage::find($packageId);

        if (!$package) {
            abort(404, 'Package not found');
        }

        if (!$package->isAvailable()) {
            abort(400, 'Package is not available');
        }

        // Get customer and validate Stripe payment info
        $customer = $this->customer();
        $mainCustomer = Customer::find($customer->id);

        if (!$mainCustomer) {
            abort(404, 'Customer not found');
        }

        // Validate Stripe payment info
        if (
            is_null($mainCustomer->stripe_customer_id) ||
            is_null($mainCustomer->stripe_payment_method_id) ||
            is_null($mainCustomer->stripe_card_data)
        ) {
            abort(400, 'Customer stripe payment info not found. Please bind a card first.');
        }

        // Validate card expiration
        $now = now();
        $currentYear = (int) $now->format('Y');
        $currentMonth = (int) $now->format('m');
        $expYear = (int) $mainCustomer->stripe_card_data['exp_year'];
        $expMonth = (int) $mainCustomer->stripe_card_data['exp_month'];

        if (!($expYear > $currentYear ||
            ($expYear === $currentYear && $expMonth >= $currentMonth)
        )) {
            abort(400, 'Customer stripe payment info expired');
        }

        // Get or create GameUser
        $gameUser = GameUser::where('customer_id', $customer->id)->first();

        if (!$gameUser) {
            $gameUser = GameUser::create([
                'customer_id' => $customer->id,
                'max_energy' => 20,
                'energy_recovery_interval_hours' => 1,
                'energy_recovery_amount' => 1,
            ]);

            // Give initial energy
            $gameUser->addEnergy(20, 'initial_energy', null, null, [
                'zh' => '初始能量',
                'en' => 'Initial energy',
                'cn' => '初始能量',
            ]);
        }

        // Create purchase record
        $purchase = CoinPackagePurchase::create([
            'customer_id' => $gameUser->_id,
            'coin_package_id' => $package->_id,
            'status' => 'pending',
            'amount_usd' => $package->price_usd,
            'coins_amount' => $package->amount,
        ]);

        $purchase->associateCustomer($gameUser);
        $purchase->associateCoinPackage($package);

        // Convert USD to cents for Stripe (Stripe uses smallest currency unit)
        $stripeAmount = (int) ($package->price_usd * 100);

        if ($stripeAmount < 50) { // Minimum $0.50
            abort(400, "Amount too small. Minimum charge is $0.50");
        }

        // Create payment via Stripe
        try {
            $stripeData = [
                "amount" => $stripeAmount,
                "currency" => 'usd',
                "customer_id" => $mainCustomer->stripe_customer_id,
                "payment_method_id" => $mainCustomer->stripe_payment_method_id,
                "metadata" => [
                    "model_type" => 'coin_package_purchase',
                    "model_id" => $purchase->_id,
                    "coin_package_id" => $package->_id,
                    "coins_amount" => $package->amount,
                ]
            ];

            $url = env('TCG_BID_STRIPE_BASE_URL', 'http://192.168.0.83:8083') . '/bind-card/charge';
            $response = Http::post($url, $stripeData);

            if ($response->failed()) {
                $error = $response->json()['error'] ?? 'Stripe API request failed';
                throw new \Exception(json_encode($error));
            }

            // Update purchase record with Stripe info
            $purchase->update([
                'stripe_payment_intent_id' => $response['id'] ?? null,
                'stripe_client_secret' => $response['clientSecret'] ?? null,
            ]);

            return response()->json([
                'package_id' => $package->_id,
                'purchase_id' => $purchase->_id,
                'amount' => $package->amount,
                'price_usd' => $package->price_usd,
                'client_secret' => $response['clientSecret'] ?? null,
                'payment_intent_id' => $response['id'] ?? null,
                'status' => 'pending',
                'message' => 'Payment initiated. Awaiting confirmation.',
            ], 200);
        } catch (\Exception $e) {
            // Mark purchase as failed
            $purchase->markAsFailed();

            Log::error('Stripe coin package purchase failed: ' . $e->getMessage(), [
                'exception' => $e,
                'customer_id' => $customer->id,
                'package_id' => $package->_id,
                'purchase_id' => $purchase->_id,
            ]);

            return response()->json([
                'message' => 'Payment processing failed',
                'error' => json_decode($e->getMessage(), true) ?: $e->getMessage()
            ], 400);
        }
    }

    /**
     * Verify IAP receipt (Apple App Store or Google Play Store)
     * Frontend sends receipt after IAP purchase completes
     */
    public function verifyIAPReceipt(Request $request)
    {
        $packageId = $request->input('package_id');
        $platform = $request->input('platform'); // 'apple' or 'google'
        $receipt = $request->input('receipt'); // Receipt data from store
        $transactionId = $request->input('transaction_id'); // Store transaction ID
        $productId = $request->input('product_id'); // Store product ID

        if (!$packageId || !$platform || !$receipt || !$transactionId) {
            abort(400, 'Missing required fields: package_id, platform, receipt, transaction_id');
        }

        if (!in_array($platform, ['apple', 'google'])) {
            abort(400, 'Platform must be "apple" or "google"');
        }

        $package = CoinPackage::find($packageId);
        if (!$package) {
            abort(404, 'Package not found');
        }

        if (!$package->isAvailable()) {
            abort(400, 'Package is not available');
        }

        // Get or create GameUser
        $customer = $this->customer();
        $gameUser = GameUser::where('customer_id', $customer->id)->first();

        if (!$gameUser) {
            $gameUser = GameUser::create([
                'customer_id' => $customer->id,
                'max_energy' => 20,
                'energy_recovery_interval_hours' => 1,
                'energy_recovery_amount' => 1,
            ]);

            // Give initial energy
            $gameUser->addEnergy(20, 'initial_energy', null, null, [
                'zh' => '初始能量',
                'en' => 'Initial energy',
                'cn' => '初始能量',
            ]);
        }

        // Check if this receipt was already processed (prevent duplicate processing)
        $existingPurchase = CoinPackagePurchase::where('iap_transaction_id', $transactionId)
            ->where('payment_method', $platform . '_iap')
            ->where('status', 'completed')
            ->first();

        if ($existingPurchase) {
            return response()->json([
                'message' => 'Receipt already processed',
                'purchase_id' => $existingPurchase->_id,
                'coins_added' => $existingPurchase->coins_amount,
                'new_coin_balance' => $gameUser->getCoinsBalance(),
            ], 200);
        }

        // Verify receipt with store (Apple/Google)
        // Note: In production, you should verify with Apple/Google servers
        // For now, we'll create the purchase and mark as completed
        // You should implement actual receipt verification here

        $isValid = $this->verifyReceiptWithStore($platform, $receipt, $transactionId, $productId);

        if (!$isValid) {
            abort(400, 'Invalid receipt or receipt verification failed');
        }

        // Create purchase record
        $purchase = CoinPackagePurchase::create([
            'customer_id' => $gameUser->_id,
            'coin_package_id' => $package->_id,
            'status' => 'completed',
            'payment_method' => $platform . '_iap',
            'iap_receipt' => $receipt,
            'iap_transaction_id' => $transactionId,
            'iap_product_id' => $productId,
            'amount_usd' => $package->price_usd,
            'coins_amount' => $package->amount,
            'completed_at' => now(),
        ]);

        $purchase->associateCustomer($gameUser);
        $purchase->associateCoinPackage($package);

        // Add coins via transaction
        $gameUser->addCoins(
            $package->amount,
            'coin_purchase',
            $package->_id,
            'coin_package',
            [
                'zh' => "購買{$package->amount}金幣",
                'en' => "Purchased {$package->amount} coins",
                'cn' => "购买{$package->amount}金币",
            ]
        );

        return response()->json([
            'purchase_id' => $purchase->_id,
            'package_id' => $package->_id,
            'amount' => $package->amount,
            'new_coin_balance' => $gameUser->getCoinsBalance(),
            'platform' => $platform,
            'transaction_id' => $transactionId,
        ], 200);
    }

    /**
     * Verify receipt with Apple or Google servers
     * This is a placeholder - implement actual verification logic
     */
    private function verifyReceiptWithStore(string $platform, string $receipt, string $transactionId, ?string $productId): bool
    {
        // TODO: Implement actual receipt verification
        // For Apple: Verify with https://buy.itunes.apple.com/verifyReceipt (production)
        //            or https://sandbox.itunes.apple.com/verifyReceipt (sandbox)
        // For Google: Verify with Google Play Developer API

        // For now, return true (you should implement actual verification)
        // In production, you MUST verify receipts server-side to prevent fraud

        return true;
    }
}
