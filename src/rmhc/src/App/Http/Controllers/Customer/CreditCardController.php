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
        $user = $this->user();
        if ($user->type === 'TEMP') {
            return response()->json([
                'message' => 'Customer is a TEMP user',
                'error_status' => 1,
                'current_user' => $user
            ], 401);
        }

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
            $customer = $this->customer();
            // Log the full error for debugging (not exposed to user)
            Log::error('Stripe setup intent failed: ' . $e->getMessage(), [
                'exception' => $e,
                'customer_id' => $customer->id
            ]);

            // Return a generic error response to the frontend
            return response()->json([
                'message' => 'Unable to reach Stripe Node Container via nginx correctly, or url given below is incorrect',
                'url' => $url,
                'data' => $data,
                'current_user' => $user,
                'error_status' => 2
            ], 401);
        }
    }
}
