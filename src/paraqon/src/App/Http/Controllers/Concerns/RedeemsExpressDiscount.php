<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Concerns;

use Illuminate\Support\Facades\Http;

trait RedeemsExpressDiscount
{
    /**
     * Redeem an auction order's discount code on the Express marketplace backend,
     * once the order is actually PAID (online Stripe callback / offline-proof
     * approval) — mirroring how marketplace orders redeem only on payment success.
     *
     * Uses a static shared secret (MARKETPLACE_INTERNAL_SECRET) rather than a user
     * token, since the payment-success context has no customer session. Best-effort;
     * the Express endpoint is idempotent by order_id.
     */
    protected function redeemExpressDiscountForOrder($order): void
    {
        $discounts = $order->discounts ?? [];
        if (empty($discounts)) return;

        $fullCode = $discounts[0]['full_code'] ?? null;
        if (empty($fullCode)) return;

        $baseUrl = env('MARKETPLACE_API_BASE_URL', 'http://192.168.0.101:8084');
        try {
            Http::withHeaders(['x-internal-secret' => env('MARKETPLACE_INTERNAL_SECRET', '')])
                ->acceptJson()
                ->post($baseUrl . '/api/service/discount-codes/redeem', [
                    'full_code' => $fullCode,
                    'customer_id' => (string) $order->customer_id,
                    'order_id' => (string) $order->_id,
                    'used_for' => 'auction',
                ]);
        } catch (\Throwable $e) {
            // best-effort — payment already succeeded; a redemption hiccup must not break settlement
        }
    }
}
