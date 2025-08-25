<?php

namespace Starsnet\Project\Auction\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CreditCardController extends Controller
{
    public function bindCard()
    {
        try {
            $url = env('TCG_BID_STRIPE_BASE_URL', 'http://192.168.0.83:8083') . '/setup-intents';

            $data = [
                "metadata" => [
                    "model_type" => "customer",
                    "model_id" => $this->customer()->id
                ]
            ];

            $response = Http::post($url, $data);
            if ($response->failed()) {
                throw new \Exception("Stripe API request failed with status: " . $response->status());
            }
            if (!isset($response['client_secret'])) {
                throw new \Exception("Invalid response from Stripe API: missing client_secret");
            }

            return [
                'message' => 'Created Setup Intent on Stripe successfully',
                'client_secret' => $response['client_secret'],
                'account' => $this->account()
            ];
        } catch (\Exception $e) {
            // Log the full error for debugging (not exposed to user)
            Log::error('Stripe setup intent failed: ' . $e->getMessage(), [
                'exception' => $e,
                'customer_id' => $this->customer()->id
            ]);

            // Return a generic error response to the frontend
            return response()->json([
                'message' => 'Unable to process payment method at this time',
                'error' => 'payment_processing_error'
            ], 500);
        }
    }

    public function validateCard(): array
    {
        $now = now();

        // Get Card info
        $stripeCardData = $this->customer()->stripe_card_data;
        if (is_null($stripeCardData)) {
            return [
                'message' => 'Customer stripe payment info not found',
                'is_card_valid' => false,
                'stripe_card_data' => null
            ];
        }

        // Validate date
        $currentYear = (int) $now->format('Y');
        $currentMonth = (int) $now->format('m');
        $expYear = (int) $stripeCardData['exp_year'];
        $expMonth = (int) $stripeCardData['exp_month'];

        if ($expYear > $currentYear) {
            return [
                'message' => 'Customer stripe payment is valid',
                'is_card_valid' => true,
                'stripe_card_data' => $stripeCardData,
            ];
        }

        if ($expYear === $currentYear && $expMonth >= $currentMonth) {
            return [
                'message' => 'Customer stripe payment is valid',
                'is_card_valid' => true,
                'stripe_card_data' => $stripeCardData,
            ];
        }

        return [
            'message' => 'Customer stripe payment is expired',
            'is_card_valid' => false,
            'stripe_card_data' => $stripeCardData,
        ];
    }
}
