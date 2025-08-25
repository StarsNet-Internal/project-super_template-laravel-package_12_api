<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Enums
use App\Enums\Status;

// Models
use Starsnet\Project\Paraqon\App\Models\ConsignmentRequest;

class ConsignmentRequestController extends Controller
{
    public function getAllConsignmentRequests(): Collection
    {
        return ConsignmentRequest::where('requested_by_customer_id', $this->customer()->id)
            ->with(['items'])
            ->get();
    }

    public function createConsignmentRequest(Request $request): array
    {
        /** @var ConsignmentRequest $form */
        $form = ConsignmentRequest::create($request->except('items'));

        // Create ConsignmentRequestItem(s)
        $requestItemsCount = 0;
        foreach ($request->items as $item) {
            $form->items()->create($item);
            $requestItemsCount++;
        }
        $form->update([
            'requested_by_customer_id' => $this->customer()->id,
            'requested_items_qty' => $requestItemsCount
        ]);

        return [
            'message' => 'Created New ConsignmentRequest successfully',
            '_id' => $form->id
        ];
    }

    public function getConsignmentRequestDetails(Request $request): ConsignmentRequest
    {
        $form = ConsignmentRequest::find($request->route('consignment_request_id'));
        if (is_null($form)) abort(404, 'ConsignmentRequest not found');
        if ($form->status === Status::DELETED->value) abort(404, 'ConsignmentRequest not found');

        $customer = $this->customer();
        if ($form->requested_by_customer_id != $customer->_id) abort(404, 'Access denied');

        $form->items = $form->items()->get();
        return $form;
    }
}
