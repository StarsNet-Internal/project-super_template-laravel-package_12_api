<?php

namespace Starsnet\Project\WhiskyWhiskers\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Enums
use App\Enums\Status;
use Illuminate\Support\Arr;
// Models
use Starsnet\Project\WhiskyWhiskers\App\Models\ConsignmentRequest;

class ConsignmentRequestController extends Controller
{
    public function getAllConsignmentRequests(Request $request)
    {
        $filterParams = Arr::except($request->query(), ['per_page', 'page', 'sort_by', 'sort_order']);

        $query = ConsignmentRequest::where('status', '!=', Status::DELETED->value);
        foreach ($filterParams as $key => $value) {
            $query = $query->where($key, $value);
        }

        return $query->with([
            'requestedAccount',
            'approvedAccount',
            'items'
        ])
            ->get();
    }

    public function getConsignmentRequestDetails(Request $request)
    {
        return ConsignmentRequest::with([
            'requestedAccount',
            'approvedAccount',
            'items'
        ])
            ->find($request->route('id'));
    }

    public function approveConsignmentRequest(Request $request)
    {
        $form = ConsignmentRequest::find($request->route('id'));

        // Associate relationships
        $form->associateApprovedAccount($this->account());

        // Update ConsignmentRequestItem(s)
        $approvedItemCount = 0;
        foreach ($request->items as $item) {
            $formItem = $form->items()->where('_id', $item['_id'])->first();
            if (is_null($formItem)) continue;
            unset($item['_id']);
            $formItem->update($item);
            if ($item['is_approved'] == true) $approvedItemCount++;
        }

        // Update ConsignmentRequest
        $formAttributes = [
            "requested_by_account_id" => $request->requested_by_account_id,
            "approved_items_qty" => $approvedItemCount,
            "reply_status" => $request->reply_status,
        ];
        $formAttributes = array_filter($formAttributes, function ($value) {
            return !is_null($value) && $value != "";
        });
        $form->update($formAttributes);

        return [
            'message' => 'Approved ConsignmentRequest successfully',
            '_id' => $form->_id,
            'requested_items_qty' => $form->requested_items_qty,
            'approved_items_qty' => $approvedItemCount
        ];
    }
}
