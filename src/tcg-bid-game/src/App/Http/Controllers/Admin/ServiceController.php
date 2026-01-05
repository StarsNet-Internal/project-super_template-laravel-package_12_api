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
                'message' => [
                    'en' => 'Callback success, but event type does not belong to any of the acceptable values',
                    'zh' => '回調成功，但事件類型不屬於任何可接受的值',
                    'cn' => '回调成功，但事件类型不属于任何可接受的值',
                ],
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
                        if (is_null($customer)) abort(404, json_encode([
                            'en' => 'Customer not found',
                            'zh' => '找不到客戶',
                            'cn' => '找不到客户',
                        ]));

                        $customer->update(['stripe_payment_method_id' => $request->data['object']['payment_method']]);

                        return [
                            'message' => [
                                'en' => 'Customer updated',
                                'zh' => '客戶已更新',
                                'cn' => '客户已更新',
                            ],
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
                    if (is_null($customer)) abort(404, json_encode([
                        'en' => 'Customer not found',
                        'zh' => '找不到客戶',
                        'cn' => '找不到客户',
                    ]));

                    $customer->update([
                        'stripe_customer_id' => $request->data['object']['customer'],
                        'stripe_card_binded_at' => now(),
                        'stripe_card_data' => $request->data['object']['card']
                    ]);

                    return [
                        'message' => [
                            'en' => 'Customer updated',
                            'zh' => '客戶已更新',
                            'cn' => '客户已更新',
                        ],
                        'customer_id' => $customer->_id,
                    ];
                }
            case 'charge.succeeded': {
                    if ($model === 'coin_package_purchase') {
                        /** @var ?CoinPackagePurchase $purchase */
                        $purchase = CoinPackagePurchase::find($modelID);
                        if (is_null($purchase)) abort(404, json_encode([
                            'en' => 'CoinPackagePurchase not found',
                            'zh' => '找不到金幣包購買記錄',
                            'cn' => '找不到金币包购买记录',
                        ]));

                        // Get GameUser
                        $gameUser = GameUser::find($purchase->customer_id);
                        if (is_null($gameUser)) abort(404, json_encode([
                            'en' => 'GameUser not found',
                            'zh' => '找不到遊戲用戶',
                            'cn' => '找不到游戏用户',
                        ]));

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
                            'message' => [
                                'en' => 'Coin package purchase completed',
                                'zh' => '金幣包購買已完成',
                                'cn' => '金币包购买已完成',
                            ],
                            'purchase_id' => $purchase->_id,
                            'coins_added' => $purchase->coins_amount,
                            'new_coin_balance' => $gameUser->getCoinsBalance(),
                        ];
                    }
                    break;
                }
            default: {
                    return [
                        'message' => [
                            'en' => "Event type not handled: {$request->type}",
                            'zh' => "未處理的事件類型：{$request->type}",
                            'cn' => "未处理的事件类型：{$request->type}",
                        ],
                        'acceptable_event_types' => $acceptableEventTypes
                    ];
                }
        }

        return [
            'message' => [
                'en' => 'An unknown error occurred',
                'zh' => '發生未知錯誤',
                'cn' => '发生未知错误',
            ],
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
                'message' => [
                    'en' => 'Energy recovery process started in background',
                    'zh' => '能量恢復過程已在後台啟動',
                    'cn' => '能量恢复过程已在后台启动',
                ],
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Throwable $th) {
            Log::error('Failed to execute energy recovery command: ' . $th->getMessage(), [
                'command' => $command,
                'exception' => $th,
            ]);

            abort(500, json_encode([
                'en' => 'Failed to execute energy recovery command: ' . $command,
                'zh' => '執行能量恢復命令失敗：' . $command,
                'cn' => '执行能量恢复命令失败：' . $command,
            ]));

            return [
                'command' => $command,
                'message' => [
                    'en' => 'Failed to execute energy recovery command',
                    'zh' => '執行能量恢復命令失敗',
                    'cn' => '执行能量恢复命令失败',
                ],
            ];
        }
    }
}
