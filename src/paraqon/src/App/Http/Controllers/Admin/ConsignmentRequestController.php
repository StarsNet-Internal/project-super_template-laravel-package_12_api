<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

// Enums
use App\Enums\Status;

// Models
use Starsnet\Project\Paraqon\App\Models\ConsignmentRequest;

class ConsignmentRequestController extends Controller
{
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
            'requested_items_qty' => $requestItemsCount
        ]);

        return [
            'message' => 'Created New ConsignmentRequest successfully',
            'id' => $form->id
        ];
    }

    public function getAllConsignmentRequests(Request $request): Collection
    {
        // Exclude pagination/sorting params before filtering
        $filterParams = Arr::except($request->query(), ['per_page', 'page', 'sort_by', 'sort_order']);

        // Exclude all deleted documents first
        $query = ConsignmentRequest::with(['requestedCustomer', 'approvedAccount', 'items'])
            ->where('status', '!=', Status::DELETED->value);

        foreach ($filterParams as $key => $value) {
            $query = $query->where($key, $value);
        }

        return $query->get();
    }

    public function getConsignmentRequestDetails(Request $request): ?ConsignmentRequest
    {
        return ConsignmentRequest::with([
            'requestedCustomer',
            'approvedAccount',
            'items'
        ])
            ->find($request->route('id'));
    }

    public function updateConsignmentRequestDetails(Request $request): array
    {
        /** @var ConsignmentRequest $consignmentRequest*/
        $consignmentRequest = ConsignmentRequest::find($request->route('id'));
        if (is_null($consignmentRequest)) abort(404, 'ConsignmentRequest not found');

        $consignmentRequest->update($request->all());

        return ['message' => 'ConsignmentRequest updated successfully'];
    }

    public function approveConsignmentRequest(Request $request): array
    {
        /** @var ConsignmentRequest $consignmentRequest*/
        $consignmentRequest = ConsignmentRequest::find($request->route('id'));
        if (is_null($consignmentRequest)) abort(404, 'ConsignmentRequest not found');

        // Update ConsignmentRequestItem(s)
        $approvedItemCount = 0;
        foreach ($request->items as $item) {
            $formItem = $consignmentRequest->items()->where('_id', $item['_id'])->first();
            if (is_null($formItem)) continue;
            unset($item['_id']);
            $formItem->update($item);
            if ($item['is_approved'] == true) $approvedItemCount++;
        }

        // Update ConsignmentRequest
        $formAttributes = [
            'approved_by_account_id' => $this->account()->id,
            'requested_by_account_id' => $request->requested_by_account_id,
            'approved_items_qty' => $approvedItemCount,
            'reply_status' => $request->reply_status,
        ];
        $formAttributes = array_filter($formAttributes, function ($value) {
            return !is_null($value) && $value != "";
        });
        $consignmentRequest->update($formAttributes);

        return [
            'message' => 'Approved ConsignmentRequest successfully',
            '_id' => $consignmentRequest->id,
            'requested_items_qty' => $consignmentRequest->requested_items_qty,
            'approved_items_qty' => $approvedItemCount
        ];
    }
}
