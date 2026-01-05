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
            abort(404, json_encode([
                'en' => 'Customer not found',
                'zh' => '找不到客戶',
                'cn' => '找不到客户',
            ]));
        }

        // Validate Stripe payment info
        if (
            is_null($mainCustomer->stripe_customer_id) ||
            is_null($mainCustomer->stripe_payment_method_id) ||
            is_null($mainCustomer->stripe_card_data)
        ) {
            abort(400, json_encode([
                'en' => 'Customer stripe payment info not found. Please bind a card first.',
                'zh' => '找不到客戶的Stripe付款資訊。請先綁定卡片。',
                'cn' => '找不到客户的Stripe付款信息。请先绑定卡片。',
            ]));
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
            abort(400, json_encode([
                'en' => 'Customer stripe payment info expired',
                'zh' => '客戶的Stripe付款資訊已過期',
                'cn' => '客户的Stripe付款信息已过期',
            ]));
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
            abort(400, json_encode([
                'en' => 'Amount too small. Minimum charge is $0.50',
                'zh' => '金額太小。最低收費為$0.50',
                'cn' => '金额太小。最低收费为$0.50',
            ]));
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
                'message' => [
                    'en' => 'Payment initiated. Awaiting confirmation.',
                    'zh' => '付款已啟動。等待確認中。',
                    'cn' => '付款已启动。等待确认中。',
                ],
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
                'message' => [
                    'en' => 'Payment processing failed',
                    'zh' => '付款處理失敗',
                    'cn' => '付款处理失败',
                ],
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
            abort(400, json_encode([
                'en' => 'Missing required fields: package_id, platform, receipt, transaction_id',
                'zh' => '缺少必填欄位：package_id, platform, receipt, transaction_id',
                'cn' => '缺少必填字段：package_id, platform, receipt, transaction_id',
            ]));
        }

        if (!in_array($platform, ['apple', 'google'])) {
            abort(400, json_encode([
                'en' => 'Platform must be "apple" or "google"',
                'zh' => '平台必須是「apple」或「google」',
                'cn' => '平台必须是「apple」或「google」',
            ]));
        }

        $package = CoinPackage::find($packageId);
        if (!$package) {
            abort(404, json_encode([
                'en' => 'Package not found',
                'zh' => '找不到金幣包',
                'cn' => '找不到金币包',
            ]));
        }

        if (!$package->isAvailable()) {
            abort(400, json_encode([
                'en' => 'Package is not available',
                'zh' => '金幣包不可用',
                'cn' => '金币包不可用',
            ]));
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
                'message' => [
                    'en' => 'Receipt already processed',
                    'zh' => '收據已處理',
                    'cn' => '收据已处理',
                ],
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
            abort(400, json_encode([
                'en' => 'Invalid receipt or receipt verification failed',
                'zh' => '無效的收據或收據驗證失敗',
                'cn' => '无效的收据或收据验证失败',
            ]));
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
