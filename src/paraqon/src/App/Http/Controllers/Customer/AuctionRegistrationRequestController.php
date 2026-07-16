<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

// Enums
use App\Enums\Status;
use App\Enums\ReplyStatus;

// Models
use App\Models\Store;
use Illuminate\Support\Collection;
use Starsnet\Project\Paraqon\App\Models\AuctionLot;
use Starsnet\Project\Paraqon\App\Models\AuctionRegistrationRequest;
use Starsnet\Project\Paraqon\App\Models\Deposit;
use Starsnet\Project\Paraqon\App\Http\Controllers\Concerns\ReadsParaqonConfiguration;

class AuctionRegistrationRequestController extends Controller
{
    use ReadsParaqonConfiguration;
    public function registerAuction(Request $request)
    {
        // Check User
        $user = $this->user();
        if ($user->type === 'TEMP') {
            return response()->json([
                'message' => 'Customer is a TEMP user',
                'error_status' => 1,
                'current_user' => $user
            ], 401);
        }

        // Check CustomerGroup for reply_status value
        $customer = $this->customer();
        $hasWaivedAuctionRegistrationGroup = $customer->groups()
            ->where('is_waived_auction_registration_deposit', true)
            ->exists();

        /** @var ?Store $store */
        $store = Store::find($request->store_id);
        if (is_null($store)) abort(404, 'Auction not found');

        // Check if there's existing AuctionRegistrationRequest
        /** @var AuctionRegistrationRequest $oldForm */
        $oldForm = AuctionRegistrationRequest::where('requested_by_customer_id', $customer->id)
            ->where('store_id', $request->store_id)
            ->first();

        $replyStatus = $hasWaivedAuctionRegistrationGroup
            ? ReplyStatus::APPROVED->value
            : ReplyStatus::PENDING->value;

        if (!is_null($oldForm)) {
            $oldFormAttributes = [
                'approved_by_account_id' => null,
                'status' => Status::ACTIVE->value,
                'reply_status' => $replyStatus,
            ];

            // Calculate paddle_id if not exists in original AuctionRegistrationRequest yet
            if ($oldForm->paddle_id === null && $replyStatus === ReplyStatus::APPROVED->value) {
                $newPaddleID = null;

                $allPaddles = AuctionRegistrationRequest::where('store_id', $store->id)
                    ->pluck('paddle_id')
                    ->filter(fn($id) => is_numeric($id))
                    ->map(fn($id) => (int) $id)
                    ->sort()
                    ->values();

                $latestPaddleId = $allPaddles->last();

                $newPaddleID = is_null($latestPaddleId)
                    ? $store->paddle_number_start_from ?? 1
                    : $latestPaddleId + 1;

                if (is_numeric($newPaddleID)) $oldFormAttributes['paddle_id'] = $newPaddleID;
            }

            $oldForm->update($oldFormAttributes);

            return [
                'message' => 'Re-activated previously created AuctionRegistrationRequest successfully',
                'id' => $oldForm->_id,
            ];
        }

        $newFormAttributes = [
            'requested_by_customer_id' => $customer->_id,
            'store_id' => $request->store_id,
            'status' => Status::ACTIVE->value,
            'paddle_id' => null,
            'reply_status' => $replyStatus,
        ];

        if ($replyStatus === ReplyStatus::APPROVED->value) {
            $newPaddleID = null;

            $allPaddles = AuctionRegistrationRequest::where('store_id', $store->id)
                ->pluck('paddle_id')
                ->filter(fn($id) => is_numeric($id))
                ->map(fn($id) => (int) $id)
                ->sort()
                ->values();

            $latestPaddleId = $allPaddles->last();

            $newPaddleID = is_null($latestPaddleId)
                ? $store->paddle_number_start_from ?? 1
                : $latestPaddleId + 1;

            if (is_numeric($newPaddleID)) $newFormAttributes['paddle_id'] = $newPaddleID;
        }

        $newForm = AuctionRegistrationRequest::create($newFormAttributes);

        return [
            'message' => 'Created New AuctionRegistrationRequest successfully',
            'id' => $newForm->id,
        ];
    }

    public function getDepositQuote(Request $request): array
    {
        [
            'form' => $form,
            'lot' => $auctionLot,
            'amount' => $amount,
        ] = $this->resolveLotDepositContext(
            $request->route('id'),
            $request->query('auction_lot_id')
        );

        $existingDeposit = $this->findValidLotDeposit($form->id, $auctionLot->id);

        return [
            'auction_registration_request_id' => $form->id,
            'auction_lot_id' => $auctionLot->id,
            'permission_type' => $auctionLot->permission_type,
            'amount' => $amount,
            'currency' => 'HKD',
            'has_valid_deposit' => !is_null($existingDeposit),
            'existing_deposit_id' => $existingDeposit?->id,
        ];
    }

    public function createDeposit(Request $request)
    {
        $user = $this->user();
        if ($user->type === 'TEMP') {
            return response()->json([
                'message' => 'Customer is a TEMP user',
                'error_status' => 1,
                'current_user' => $user
            ], 401);
        }

        // Extract attributes from $request
        $paymentMethod = $request->payment_method;
        $amount = $request->amount;
        $currency = $request->input('currency', 'HKD');
        $conversionRate = $request->input('conversion_rate', '1.00');
        $auctionLotID = $request->auction_lot_id;

        // Get authenticated User information
        $customer = $this->customer();

        $lotPermissionType = null;
        if (!is_null($auctionLotID)) {
            [
                'form' => $form,
                'lot' => $auctionLot,
                'amount' => $amount,
            ] = $this->resolveLotDepositContext($request->route('id'), $auctionLotID);
            $lotPermissionType = $auctionLot->permission_type;

            if (!is_null($this->findValidLotDeposit($form->id, $auctionLot->id))) {
                abort(409, 'A valid Deposit already exists for this Auction Lot');
            }
        } else {
            /** @var AuctionRegistrationRequest $form */
            $form = AuctionRegistrationRequest::find($request->route('id'));
            if (is_null($form)) abort(404, 'Auction Registration Request not found');
            if ($form->requested_by_customer_id != $customer->_id) abort(404, 'You do not have the permission to create Deposit');
        }

        switch ($paymentMethod) {
            case 'ONLINE':
                // Create Deposit
                $depositAttributes = [
                    'requested_by_customer_id' => $customer->id,
                    'auction_registration_request_id' => $form->id,
                    'payment_method' => 'ONLINE',
                    'amount' => $amount,
                    'currency' => 'HKD',
                    'payment_information' => [
                        'currency' => $currency,
                        'conversion_rate' => $conversionRate
                    ],
                    'permission_type' => $lotPermissionType,
                    'auction_lot_id' => $auctionLotID,
                ];
                $deposit = Deposit::create($depositAttributes);
                $deposit->updateStatus('submitted');

                // Create Stripe payment intent
                $stripeAmount = (int) $amount * 100;
                $data = [
                    "amount" => $stripeAmount,
                    "currency" => 'HKD',
                    "captureMethod" => "manual",
                    "metadata" => [
                        "model_type" => "deposit",
                        "model_id" => $deposit->id
                    ]
                ];

                try {
                    $url = env('PARAQON_STRIPE_BASE_URL', 'https://payment.paraqon.starsnet.hk') . '/payment-intents';
                    $res = Http::post($url, $data);
                    $deposit->update([
                        'online' => [
                            'payment_intent_id' => $res['id'],
                            'client_secret' => $res['clientSecret'],
                            'api_response' => null
                        ]
                    ]);
                } catch (\Throwable $th) {
                    abort(404, 'Connection to Payment API Failed');
                }

                return [
                    'message' => 'Created New Deposit successfully',
                    'deposit' => $deposit
                ];
            case 'OFFLINE':
                $depositAttributes = [
                    'requested_by_customer_id' => $customer->_id,
                    'auction_registration_request_id' => $form->_id,
                    'payment_method' => 'OFFLINE',
                    'amount' => $amount,
                    'currency' => 'HKD',
                    'offline' => [
                        'image' => $request->image,
                        'uploaded_at' => now(),
                        'api_response' => null
                    ],
                    'payment_information' => [
                        'currency' => $currency,
                        'conversion_rate' => $conversionRate
                    ],
                    'permission_type' => $lotPermissionType,
                    'auction_lot_id' => $auctionLotID,
                ];
                $deposit = Deposit::create($depositAttributes);
                $deposit->updateStatus('submitted');

                return [
                    'message' => 'Created New Deposit successfully',
                    'deposit' => $deposit
                ];
            default:
                return ['message' => 'payment_method ' . $paymentMethod . ' is not supported.'];
        }
    }

    private function resolveLotDepositContext($registrationRequestID, $auctionLotID): array
    {
        if (is_null($auctionLotID) || $auctionLotID === '') {
            abort(422, 'auction_lot_id is required');
        }

        $customer = $this->customer();

        /** @var ?AuctionRegistrationRequest $form */
        $form = AuctionRegistrationRequest::find($registrationRequestID);
        if (is_null($form)) abort(404, 'Auction Registration Request not found');
        if ($form->requested_by_customer_id != $customer->_id) {
            abort(404, 'You do not have the permission to create Deposit');
        }

        /** @var ?AuctionLot $auctionLot */
        $auctionLot = AuctionLot::find($auctionLotID);
        if (is_null($auctionLot) || $auctionLot->status == Status::DELETED->value) {
            abort(404, 'Auction Lot not found');
        }
        if ((string) $auctionLot->store_id !== (string) $form->store_id) {
            abort(422, 'Auction Lot does not belong to this Auction Registration Request');
        }
        if (is_null($auctionLot->permission_type) || $auctionLot->permission_type === '') {
            abort(422, 'Auction Lot does not require a high-value Deposit');
        }

        /** @var ?Store $store */
        $store = Store::find($form->store_id);
        if (is_null($store) || $store->status == Status::DELETED->value) {
            abort(404, 'Store not found');
        }

        $amount = $this->resolveHighValueDepositAmount($store, $auctionLot);
        if (is_null($amount)) {
            abort(422, 'High-value Deposit amount is not configured for this Auction Lot');
        }

        return [
            'form' => $form,
            'lot' => $auctionLot,
            'store' => $store,
            'amount' => $amount,
        ];
    }

    private function findValidLotDeposit($registrationRequestID, $auctionLotID): ?Deposit
    {
        return Deposit::where('auction_registration_request_id', $registrationRequestID)
            ->where('auction_lot_id', $auctionLotID)
            ->where('status', Status::ACTIVE->value)
            ->whereIn('reply_status', [
                ReplyStatus::PENDING->value,
                ReplyStatus::APPROVED->value,
            ])
            ->get()
            ->first(function (Deposit $deposit) {
                if ($deposit->payment_method === 'ONLINE') {
                    return $deposit->current_deposit_status === 'on-hold';
                }

                return in_array($deposit->current_deposit_status, ['submitted', 'on-hold'], true);
            });
    }

    public function registerAuctionAgain(Request $request): array
    {
        // Get authenticated User information
        $customer = $this->customer();

        // Extract attributes from $request
        $amount = $request->amount;
        $currency = $request->currency;
        $conversionRate = $request->conversion_rate;

        // Check if there's existing AuctionRegistrationRequest
        /** @var ?AuctionRegistrationRequest $form */
        $form = AuctionRegistrationRequest::find($request->route('auction_registration_request_id'));
        if (is_null($form)) abort(404, 'Auction Registration Request not found');
        if ($form->status != Status::ACTIVE->value) abort(404, 'AuctionRegistrationRequest not found');
        if ($form->requested_by_customer_id != $customer->_id) abort(404, 'You do not have the permission to update this AuctionRegistrationRequest');

        // Create Deposit
        /** @var Deposit $deposit */
        $deposit = Deposit::create([
            'customer_id' => $customer->_id,
            'auction_registration_request_id' => $form->_id,
            'payment_method' => 'ONLINE',
            'amount' => $amount,
            'currency' => 'HKD',
            'payment_information' => [
                'currency' => $currency,
                'conversion_rate' => $conversionRate
            ]
        ]);
        $deposit->updateStatus('submitted');

        // Create payment-intent
        $stripeAmount = (int) $amount * 100;
        $data = [
            "amount" => $stripeAmount,
            "currency" => 'HKD',
            "captureMethod" => "manual",
            "metadata" => [
                "model_type" => "checkout",
                "model_id" => $deposit->_id
            ]
        ];

        $url = env('PARAQON_STRIPE_BASE_URL', 'https://payment.paraqon.starsnet.hk') . '/payment-intents';
        $res = Http::post($url, $data);
        $deposit->update([
            'online' => [
                'payment_intent_id' => $res['id'],
                'client_secret' => $res['clientSecret'],
                'api_response' => null
            ]
        ]);

        return [
            'message' => 'Created New AuctionRegistrationRequest successfully',
            'auction_registration_request_id' => $form->id,
            'deposit_id' => $deposit->id
        ];
    }

    public function getAllRegisteredAuctions(): Collection
    {
        return AuctionRegistrationRequest::where('requested_by_customer_id', $this->customer()->id)
            ->with(['store'])
            ->get();
    }

    public function getRegisteredAuctionDetails(Request $request): AuctionRegistrationRequest
    {
        /** @var ?AuctionRegistrationRequest $form */
        $form = null;
        if ($request->filled('id')) {
            $form = AuctionRegistrationRequest::with(['store', 'deposits'])->find($request->id);
        } else if ($request->filled('store_id')) {
            $form = AuctionRegistrationRequest::where('store_id', $request->store_id)
                ->where('requested_by_customer_id', $this->customer()->id)
                ->with(['store', 'deposits'])
                ->latest()
                ->first();
        }
        if (is_null($form)) abort(404, 'AuctionRegistrationRequest not found');

        return $form;
    }

    public function archiveAuctionRegistrationRequest(Request $request): array
    {
        /** @var ?AuctionRegistrationRequest $form */
        $form = AuctionRegistrationRequest::find($request->route('auction_registration_request_id'));
        if (is_null($form)) abort(404, 'AuctionRegistrationRequest not found');

        $customer = $this->customer();
        if ($form->requested_by_customer_id != $customer->_id) abort(403, 'Access denied');

        $form->update(['status' => Status::ARCHIVED->value]);

        return ['message' => 'Updated AuctionRegistrationRequest status to ARCHIVED'];
    }
}
