<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Enums
use App\Enums\ReplyStatus;
use App\Enums\Status;

// Models
use App\Models\Customer;
use App\Models\Store;
use Starsnet\Project\Paraqon\App\Models\AuctionRegistrationRequest;

class AuctionRegistrationRequestController extends Controller
{
    public function registerAuction(Request $request): array
    {
        /** @var ?Store $store */
        $store = Store::find($request->store_id);
        if (is_null($store)) abort(404, 'Store not found');

        /** @var ?Customer $customer */
        $customer = Customer::find($request->customer_id);
        if (is_null($customer)) abort(404, 'Customer not found');

        // Check if there's existing AuctionRegistrationRequest
        /** @var ?AuctionRegistrationRequest $oldForm */
        $oldForm = AuctionRegistrationRequest::where('requested_by_customer_id', $customer->id)
            ->where('store_id', $store->id)
            ->first();

        if ($oldForm instanceof AuctionRegistrationRequest) {
            $oldFormAttributes = [
                'approved_by_account_id' => $this->account()->id,
                'status' => Status::ACTIVE->value,
                'reply_status' => ReplyStatus::APPROVED->value,
            ];
            $oldForm->update($oldFormAttributes);

            return [
                'message' => 'Re-activated previously created AuctionRegistrationRequest successfully',
                'id' => $oldForm->id,
            ];
        }

        // Create AuctionRegistrationRequest
        $newFormAttributes = [
            'requested_by_customer_id' => $customer->id,
            'store_id' => $store->id,
        ];
        /** @var AuctionRegistrationRequest $newForm */
        $newForm = AuctionRegistrationRequest::create($newFormAttributes);

        return [
            'message' => 'Created New AuctionRegistrationRequest successfully',
            'id' => $newForm->id,
        ];
    }

    public function getAllRegisteredAuctions(): Collection
    {
        return AuctionRegistrationRequest::with(['store'])->get();
    }

    public function getRegisteredAuctionDetails(Request $request): AuctionRegistrationRequest
    {
        /** @var ?AuctionRegistrationRequest $form */
        $form = null;

        if ($request->filled('id')) {
            /** @var ?AuctionRegistrationRequest $form */
            $form = AuctionRegistrationRequest::with(['store', 'deposits'])->find($request->id);
        }
        if ($request->filled('store_id')) {
            /** @var ?AuctionRegistrationRequest $form */
            $form = AuctionRegistrationRequest::where('store_id', $request->store_id)
                ->with(['store', 'deposits'])
                ->latest()
                ->first();
        }

        if (is_null($form)) abort(404, 'AuctionRegistrationRequest not found');
        if ($form->status == Status::DELETED->value) abort(404, 'AuctionRegistrationRequest not found');

        return $form;
    }

    public function archiveAuctionRegistrationRequest(Request $request): array
    {
        /** @var ?AuctionRegistrationRequest $form */
        $form = AuctionRegistrationRequest::find($request->route('id'));
        if (is_null($form)) abort(404, 'AuctionRegistrationRequest not found');

        $form->update(['status' => Status::ARCHIVED->value]);

        return ['message' => 'Updated AuctionRegistrationRequest status to ARCHIVED'];
    }

    public function updateAuctionRegistrationRequest(Request $request): array
    {
        /** @var ?AuctionRegistrationRequest $form */
        $form = AuctionRegistrationRequest::find($request->route('id'));
        if (is_null($form)) abort(404, 'AuctionRegistrationRequest not found');

        // Update AuctionRegistrationRequest
        $form->update($request->all());

        return ['message' => 'Updated AuctionRegistrationRequest'];
    }
}
