<?php

namespace Starsnet\Project\TcgBidGame\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// Models
use App\Models\Customer;
use Starsnet\Project\TcgBidGame\App\Models\CoinPackagePurchase;
use Starsnet\Project\TcgBidGame\App\Models\GameUser;

class ServiceController extends Controller
{
    public function paymentCallback(Request $request): array
    {
        $acceptableEventTypes = [
            'charge.succeeded',
            'setup_intent.succeeded',
            'payment_method.attached'
        ];

        if (!in_array($request->type, $acceptableEventTypes)) {
            return [
                'message' => 'Callback success, but event type does not belong to any of the acceptable values',
                'acceptable_values' => $acceptableEventTypes
            ];
        }

        // Extract metadata from $request
        $model = $request->data['object']['metadata']['model_type'] ?? null;
        $modelID = $request->data['object']['metadata']['model_id'] ?? null;

        // ===============
        // Handle Events
        // ===============
        switch ($request->type) {
            case 'setup_intent.succeeded': {
                    // ---------------------
                    // If bind card success
                    // ---------------------
                    if ($model == 'customer') {
                        /** @var ?Customer $customer */
                        $customer = Customer::find($modelID);
                        if (is_null($customer)) abort(404, 'Customer not found');

                        $customer->update(['stripe_payment_method_id' => $request->data['object']['payment_method']]);

                        return [
                            'message' => 'Customer updated',
                            'customer_id' => $customer->id,
                        ];
                    }
                    break;
                }
            case 'payment_method.attached': {
                    // ---------------------
                    // When Stripe DB created a new customer
                    // ---------------------
                    /** @var ?Customer $customer */
                    $customer = Customer::where('stripe_payment_method_id', $request->data['object']['id'])
                        ->latest()
                        ->first();
                    if (is_null($customer)) abort(404, 'Customer not found');

                    $customer->update([
                        'stripe_customer_id' => $request->data['object']['customer'],
                        'stripe_card_binded_at' => now(),
                        'stripe_card_data' => $request->data['object']['card']
                    ]);

                    return [
                        'message' => 'Customer updated',
                        'customer_id' => $customer->_id,
                    ];
                }
            case 'charge.succeeded': {
                    if ($model === 'coin_package_purchase') {
                        /** @var ?CoinPackagePurchase $purchase */
                        $purchase = CoinPackagePurchase::find($modelID);
                        if (is_null($purchase)) abort(404, 'CoinPackagePurchase not found');

                        // Get GameUser
                        $gameUser = GameUser::find($purchase->customer_id);
                        if (is_null($gameUser)) abort(404, 'GameUser not found');

                        // Mark purchase as completed
                        $purchase->markAsCompleted();

                        // Add coins via transaction
                        $gameUser->addCoins(
                            $purchase->coins_amount,
                            'coin_purchase',
                            $purchase->coin_package_id,
                            'coin_package',
                            [
                                'zh' => "購買{$purchase->coins_amount}金幣",
                                'en' => "Purchased {$purchase->coins_amount} coins",
                                'cn' => "购买{$purchase->coins_amount}金币",
                            ]
                        );

                        return [
                            'message' => 'Coin package purchase completed',
                            'purchase_id' => $purchase->_id,
                            'coins_added' => $purchase->coins_amount,
                            'new_coin_balance' => $gameUser->getCoinsBalance(),
                        ];
                    }
                    break;
                }
            default: {
                    return [
                        'message' => "Event type not handled: {$request->type}",
                        'acceptable_event_types' => $acceptableEventTypes
                    ];
                }
        }

        return [
            'message' => 'An unknown error occurred',
            'received_request_body' => $request->all()
        ];
    }

    public function recoverEnergy(Request $request): array
    {
        // Get current database configuration from the request context
        $mongodbDatabase = config('database.connections.mongodb.database');

        // Execute in background (the & at the end makes it run in background)
        $artisanPath = base_path('artisan');
        $command = "php {$artisanPath} game:recover-energy '{$mongodbDatabase}' > /dev/null 2>&1 &";

        try {
            exec($command);
            return [
                'command' => $command,
                'message' => 'Energy recovery process started in background',
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Throwable $th) {
            Log::error('Failed to execute energy recovery command: ' . $th->getMessage(), [
                'command' => $command,
                'exception' => $th,
            ]);
            
            abort(500, 'Failed to execute energy recovery command: ' . $command);

            return [
                'command' => $command,
                'message' => 'Failed to execute energy recovery command'
            ];
        }
    }
}
