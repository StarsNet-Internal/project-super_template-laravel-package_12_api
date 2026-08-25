<?php

namespace Starsnet\Project\Paraqon\App\Services;

use App\Enums\CheckoutApprovalStatus;
use App\Enums\ShipmentDeliveryStatus;
use App\Models\Checkout;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MongoDB\BSON\UTCDateTime;

class VaultPaymentService
{
    public const PAYMENT_WINDOW_MINUTES = 30;
    public const STORE_SLUG = 'default-main-store';

    public function paymentExpiresAt()
    {
        return now()->addMinutes(self::PAYMENT_WINDOW_MINUTES);
    }

    public function createPaymentIntent(Order $order, Checkout $checkout): array
    {
        $data = [
            'amount' => (int) $order->getTotalPrice() * 100,
            'currency' => 'HKD',
            'captureMethod' => 'manual',
            'metadata' => [
                'model_type' => 'checkout',
                'model_id' => $checkout->_id,
                'custom_event_type' => 'five_day_delay',
            ],
        ];

        $url = env('PARAQON_STRIPE_BASE_URL', 'https://payment.paraqon.starsnet.hk')
            . '/payment-intents';
        $response = Http::post($url, $data);

        if (!$response->successful()) {
            abort(502, 'Unable to create Stripe payment intent');
        }

        $paymentIntentID = $response['id'] ?? null;
        $clientSecret = $response['clientSecret'] ?? null;
        if (is_null($paymentIntentID) || is_null($clientSecret)) {
            abort(502, 'Invalid Stripe payment intent response');
        }

        $checkout->update([
            'amount' => number_format($order->getTotalPrice(), 2, '.', ''),
            'currency' => 'HKD',
            'online' => [
                'payment_intent_id' => $paymentIntentID,
                'client_secret' => $clientSecret,
                'api_response' => null,
            ],
        ]);

        return [
            'payment_intent_id' => $paymentIntentID,
            'client_secret' => $clientSecret,
        ];
    }

    public function replacePaymentIntent(Order $order, Checkout $checkout): array
    {
        $this->cancelPaymentIntent($checkout);

        return $this->createPaymentIntent($order, $checkout);
    }

    public function expireDueOrders(): array
    {
        $orders = Order::where('payment_expires_at', '<=', now())
            ->where('is_paid', '!=', true)
            ->whereNull('scheduled_payment_at')
            ->whereNull('payment_expired_at')
            ->where('current_status', '!=', Str::slug(ShipmentDeliveryStatus::CANCELLED->value))
            ->get();

        $expiredOrderIDs = [];
        foreach ($orders as $order) {
            if ($this->expireIfDue($order)) {
                $expiredOrderIDs[] = (string) $order->_id;
            }
        }

        return $expiredOrderIDs;
    }

    public function expireIfDue(Order $order): bool
    {
        $order = $order->fresh();
        if (
            is_null($order)
            || !$this->isVaultOrder($order)
            || !$this->isAwaitingPayment($order)
        ) {
            return false;
        }

        if (!$this->hasPaymentWindowExpired($order)) {
            return false;
        }

        $checkout = $order->checkout()->latest()->first();
        if ($checkout instanceof Checkout) {
            $this->cancelPaymentIntent($checkout);
        }

        // The MongoDB notification listener restores Vault inventory when the
        // Order enters CANCELLED, covering automatic, customer and admin cancellation.
        $order->update([
            'payment_expired_at' => now(),
        ]);
        $order->updateStatus(ShipmentDeliveryStatus::CANCELLED->value);

        if ($checkout instanceof Checkout) {
            $checkout->update([
                'approval.status' => CheckoutApprovalStatus::REJECTED->value,
                'approval.reason' => 'Payment window expired after '
                    . self::PAYMENT_WINDOW_MINUTES
                    . ' minutes',
                'approval.updated_at' => now(),
            ]);
        }

        return true;
    }

    public function isAwaitingPayment(Order $order): bool
    {
        return !$order->is_paid
            && is_null($order->scheduled_payment_at)
            && is_null($order->payment_expired_at)
            && $order->current_status !== Str::slug(ShipmentDeliveryStatus::CANCELLED->value);
    }

    public function hasPaymentWindowExpired(Order $order): bool
    {
        $expiresAt = $order->payment_expires_at;
        if (is_null($expiresAt)) {
            return false;
        }
        if ($expiresAt instanceof UTCDateTime) {
            $expiresAt = $expiresAt->toDateTime();
        }

        return now()->gte($expiresAt);
    }

    public function isVaultOrder(Order $order): bool
    {
        return $order->store?->slug === self::STORE_SLUG;
    }

    private function cancelPaymentIntent(Checkout $checkout): bool
    {
        $paymentIntentID = data_get($checkout, 'online.payment_intent_id');
        if (is_null($paymentIntentID)) {
            return true;
        }

        try {
            $url = env('PARAQON_STRIPE_BASE_URL', 'https://payment.paraqon.starsnet.hk')
                . '/payment-intents/' . $paymentIntentID . '/cancel';
            $response = Http::post($url);

            if (!$response->successful()) {
                Log::warning('Unable to cancel Vault payment intent', [
                    'checkout_id' => (string) $checkout->_id,
                    'payment_intent_id' => $paymentIntentID,
                    'response' => $response->body(),
                ]);
            }

            return $response->successful();
        } catch (\Throwable $throwable) {
            Log::warning('Unable to cancel Vault payment intent', [
                'checkout_id' => (string) $checkout->_id,
                'payment_intent_id' => $paymentIntentID,
                'message' => $throwable->getMessage(),
            ]);

            return false;
        }
    }

}
