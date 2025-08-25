<?php

namespace Starsnet\Project\WhiskyWhiskers\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Models
use Starsnet\Project\WhiskyWhiskers\App\Models\ConsignmentRequest;

class ConsignmentRequestController extends Controller
{
    public function getAllConsignmentRequests(): Collection
    {
        return ConsignmentRequest::where('requested_by_account_id', $this->account()->_id)
            ->with(['items'])
            ->get();
    }

    public function createConsignmentRequest(Request $request): array
    {
        // Create ConsignmentRequest
        $form = ConsignmentRequest::create($request->except('items'));

        // Create ConsignmentRequestItem(s)
        $requestItemsCount = 0;
        foreach ($request->items as $item) {
            $form->items()->create($item);
            $requestItemsCount++;
        }
        $form->update(['requested_items_qty' => $requestItemsCount]);

        return [
            'message' => 'Created New ConsignmentRequest successfully',
            '_id' => $form->_id
        ];
    }

    public function getConsignmentRequestDetails(Request $request): ConsignmentRequest
    {
        /** @var ?ConsignmentRequest $form */
        $form = ConsignmentRequest::find($request->route('consignment_request_id'));

        if (is_null($form)) abort(404, 'Consignment Request not found');
        if ($form->requested_by_account_id !=  $this->account()->id) abort(404, 'Access denied');

        $form->items = $form->items()->get();
        return $form;
    }
}
