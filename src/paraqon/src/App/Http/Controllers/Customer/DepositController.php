<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Enums
use App\Enums\ReplyStatus;
use App\Enums\Status;

// Models
use Starsnet\Project\Paraqon\App\Models\Deposit;

class DepositController extends Controller
{
    public function getAllDeposits(): Collection
    {
        return Deposit::where('requested_by_customer_id', $this->customer()->id)
            ->with(['auctionRegistrationRequest.store'])
            ->get();
    }

    public function getDepositDetails(Request $request): Deposit
    {
        /** @var ?Deposit $deposit */
        $deposit = Deposit::with(['auctionRegistrationRequest.store', 'depositStatuses'])
            ->find($request->route('id'));
        if (is_null($deposit)) abort(404, 'Deposit not found');
        if ($deposit->requested_by_customer_id != $this->customer()->id) abort(400, 'Access denied');

        return $deposit;
    }

    public function updateDepositDetails(Request $request): array
    {
        /** @var ?Deposit $deposit */
        $deposit = Deposit::find($request->route('id'));
        if (is_null($deposit)) abort(404, 'Deposit not found');
        if ($deposit->status != Status::ACTIVE->value) abort(404, 'Deposit not found');

        $customer = $this->customer();
        if ($deposit->requested_by_customer_id != $customer->_id) abort(404, 'You do not have the permission to update this Deposit');

        $deposit->update($request->all());

        return ['message' => 'Deposit updated successfully'];
    }

    public function cancelDeposit(Request $request): array
    {
        /** @var ?Deposit $deposit */
        $deposit = Deposit::find($request->route('id'));
        if (is_null($deposit)) abort(404, 'Deposit not found');
        if ($deposit->status != Status::ACTIVE->value) abort(404, 'Deposit not found');

        $customer = $this->customer();
        if ($deposit->requested_by_customer_id != $customer->_id) abort(404, 'You do not have the permission to update this Deposit');
        if ($deposit->reply_status != ReplyStatus::PENDING->value) abort(404, 'This Deposit has already been APPROVED/REJECTED');

        $registrationRequest = $deposit->auctionRegistrationRequest()->latest()->first();
        if (is_null($registrationRequest)) abort(404, 'AuctionRegistrationRequest not found');
        if ($registrationRequest->status != Status::ACTIVE->value) abort(404, 'AuctionRegistrationRequest not found');
        if ($registrationRequest->reply_status != ReplyStatus::PENDING) abort(404, 'This AuctionRegistrationRequest has already been APPROVED/REJECTED.');

        // Update Deposit
        $depositAttributes = [
            'status' => Status::ARCHIVED->value,
            'reply_status' => ReplyStatus::REJECTED->value
        ];
        $deposit->update($depositAttributes);

        // Update AuctionRegistrationRequest
        $requestAttributes = [
            'status' => Status::ARCHIVED->value,
            'reply_status' => ReplyStatus::REJECTED->value
        ];
        $registrationRequest->update($requestAttributes);

        return ['message' => 'Deposit updated successfully'];
    }
}
