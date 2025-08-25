<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// Enums
use App\Enums\Status;
use App\Enums\ReplyStatus;
use App\Enums\OrderPaymentMethod;

// Models
use App\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Starsnet\Project\Paraqon\App\Models\AuctionRegistrationRequest;
use Starsnet\Project\Paraqon\App\Models\Deposit;

class DepositController extends Controller
{
    public function getAllDeposits(Request $request): Collection
    {
        // Extract attributes from $request
        $auctionType = $request->input('auction_type', 'ONLINE');

        return Deposit::with([
            'auctionRegistrationRequest.store',
            'depositStatuses'
        ])
            ->whereHas('auctionRegistrationRequest', function ($query) use ($auctionType) {
                $query->whereHas('store', function ($query2) use ($auctionType) {
                    $query2->where('auction_type', $auctionType);
                });
            })
            ->latest()
            ->get();
    }

    public function getDepositDetails(Request $request): ?Deposit
    {
        return Deposit::with([
            'auctionRegistrationRequest.store',
            'approvedAccount',
            'depositStatuses',
            'requestedCustomer'
        ])
            ->find($request->route('id'));
    }

    public function updateDepositDetails(Request $request): array
    {
        /** @var ?Deposit $deposit */
        $deposit = Deposit::find($request->route('id'));
        if (is_null($deposit)) abort(404, 'Deposit not found');

        $deposit->update($request->all());

        return ['message' => 'Deposit updated successfully'];
    }

    public function approveDeposit(Request $request): array
    {
        /** @var ?Deposit $deposit */
        $deposit = Deposit::find($request->route('id'));
        if (is_null($deposit)) abort(404, 'Deposit not found');
        if (in_array($deposit->reply_status, [ReplyStatus::APPROVED->value, ReplyStatus::REJECTED->value])) {
            abort(400, 'Deposit has already been ' . $deposit->reply_status);
        }

        /** @var ?AuctionRegistrationRequest $auctionRegistrationRequest */
        $auctionRegistrationRequest = $deposit->auctionRegistrationRequest;
        if (is_null($auctionRegistrationRequest)) abort(404, 'AuctionRegistrationRequest not found');

        // Get current Account
        $account = $this->account();

        // Update Deposit and AuctionRegistrationRequest
        $replyStatus = strtoupper($request->reply_status);
        switch ($replyStatus) {
            case ReplyStatus::APPROVED->value:
                // Update Deposit
                $deposit->update([
                    'approved_by_account_id' => $account->id,
                    'reply_status' => ReplyStatus::APPROVED->value
                ]);
                $deposit->updateStatus('on-hold');

                // Get and Update paddle_id
                $storeID = $auctionRegistrationRequest->store_id;
                $store = Store::find($storeID);

                $newPaddleID = null;
                if ($store instanceof Store) {
                    $auctionType = $store->auction_type;

                    if ($auctionType == 'ONLINE') {
                        if (is_numeric($request->paddle_id)) {
                            $newPaddleID = $request->paddle_id;
                        } else if (is_null($auctionRegistrationRequest->paddle_id)) {
                            $allPaddles = AuctionRegistrationRequest::where('store_id', $storeID)
                                ->pluck('paddle_id')
                                ->filter(fn($id) => is_numeric($id))
                                ->map(fn($id) => (int) $id)
                                ->sort()
                                ->values();
                            $latestPaddleId = $allPaddles->last();

                            if (is_null($latestPaddleId)) {
                                $newPaddleID = $store->paddle_number_start_from ?? 1;
                            } else {
                                $newPaddleID = $latestPaddleId + 1;
                            }
                        } else {
                            $newPaddleID = $auctionRegistrationRequest->paddle_id;
                        }
                    } else {
                        $newPaddleID = $request->paddle_id ?? $auctionRegistrationRequest->paddle_id;
                    }
                }

                $auctionRegistrationRequest->update([
                    'approved_by_account_id' => $account->_id,
                    'paddle_id' => $newPaddleID,
                    'status' => Status::ACTIVE->value,
                    'reply_status' => ReplyStatus::APPROVED->value
                ]);
                break;
            case ReplyStatus::REJECTED->value:
                // Update Deposit
                $deposit->update([
                    'approved_by_account_id' => $account->_id,
                    'reply_status' => ReplyStatus::REJECTED->value
                ]);
                $deposit->updateStatus('rejected');

                // Update AuctionRegistrationRequest
                $requestUpdateAttributes = [
                    'approved_by_account_id' => $account->_id,
                    'status' => Status::ACTIVE->value,
                    'reply_status' => ReplyStatus::REJECTED->value
                ];
                $auctionRegistrationRequest->update($requestUpdateAttributes);
                break;
            default:
                break;
        }

        return [
            'message' => 'Deposit updated successfully'
        ];
    }

    public function cancelDeposit(Request $request): array
    {
        /** @var ?Deposit $deposit */
        $deposit = Deposit::find($request->route('id'));
        if (is_null($deposit)) abort(404, 'Deposit not found');
        if ($deposit->status != Status::ACTIVE->value) abort(404, 'Deposit not found');
        if ($deposit->reply_status != ReplyStatus::PENDING->value) abort(404, 'This Deposit has already been ' . $deposit->reply_status);

        // Update Deposit
        $deposit->update([
            'status' => Status::ARCHIVED->value,
            'reply_status' => ReplyStatus::REJECTED->value
        ]);

        // Return Deposit
        if ($deposit->payment_method == OrderPaymentMethod::ONLINE->value) {
            try {
                $paymentIntentID = $deposit->online['payment_intent_id'];
                $url = env('PARAQON_STRIPE_BASE_URL', 'https://payment.paraqon.starsnet.hk') . '/payment-intents/' . $paymentIntentID . '/cancel';

                $response = Http::post($url);
                if ($response->status() === 200) {
                    $deposit->update(['refund_id' => $response['id']]);
                    $deposit->updateStatus('cancelled');
                } else {
                    $deposit->updateStatus('return-failed');
                }
            } catch (\Throwable $th) {
                $deposit->updateStatus('return-failed');
            }
        }

        return ['message' => 'Deposit cancelled successfully'];
    }
}
