<?php

namespace Starsnet\Project\Auction\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// Enums
use App\Enums\ReplyStatus;
use App\Enums\Status;

// Models
use Starsnet\Project\Paraqon\App\Models\AuctionRegistrationRequest;

class AuctionRegistrationRequestController extends Controller
{
    public function updateAuctionRegistrationRequest(Request $request): array
    {
        /** @var ?AuctionRegistrationRequest $auctionRegistrationRequest */
        $auctionRegistrationRequest = AuctionRegistrationRequest::find($request->route('auction_registration_request_id'));
        if (is_null($auctionRegistrationRequest)) abort(404, 'AuctionRegistrationRequest not found');
        if ($auctionRegistrationRequest->requested_by_customer_id !== $this->customer()->id) abort(403, 'AuctionRegistrationRequest does not belong to this customer');

        $replyStatus = $request->reply_status;
        if (!in_array($replyStatus, [ReplyStatus::APPROVED->value, ReplyStatus::REJECTED->value])) {
            abort(400, 'reply_status should be either APPROVED/REJECTED');
        }

        $newPaddleID = null;
        if (!is_null($request->paddle_id)) {
            $newPaddleID = $request->paddle_id;
        } else if (!is_null($auctionRegistrationRequest->paddle_id)) {
            $newPaddleID = $auctionRegistrationRequest->paddle_id;
        } else {
            $allPaddles = AuctionRegistrationRequest::where('store_id', $auctionRegistrationRequest->store_id)
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
        }

        $auctionRegistrationRequest->update([
            'approved_by_account_id' => $this->account()->id,
            'paddle_id' => $newPaddleID,
            'status' => Status::ACTIVE->value,
            'reply_status' => $replyStatus
        ]);

        return [
            'message' => 'Updated AuctionRegistrationRequest successfully',
            'auction_registration_request_id' => $auctionRegistrationRequest->_id
        ];
    }
}
