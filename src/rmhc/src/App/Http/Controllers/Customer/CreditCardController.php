<?php

namespace Starsnet\Project\Rmhc\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CreditCardController extends Controller
{
    public function bindCard()
    {
        try {
            $url = env('RMHC_STRIPE_BASE_URL', 'http://192.168.0.83:8083') . '/setup-intents';

            $data = [
                'metadata' => [
                    'model_type' => 'customer',
                    'model_id' => $this->customer()->id,
                    'custom_event_type' => 'bind_credit_card'
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
            $customerID = $this->customer()->id;
            // Log the full error for debugging (not exposed to user)
            Log::error('Stripe setup intent failed: ' . $e->getMessage(), [
                'exception' => $e,
                'customer_id' => $customerID
            ]);

            return response()->json([
                'message' => 'Unable to process payment method at this time',
                'given_url' => $url,
                'given_data' => $data,
                'customer_id' => $customerID,
                'error' => 'payment_processing_error'
            ], 500);
        }
    }
}
