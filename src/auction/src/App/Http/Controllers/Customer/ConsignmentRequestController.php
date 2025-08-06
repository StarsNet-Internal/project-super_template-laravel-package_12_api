<?php

namespace Starsnet\Project\Auction\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// Models
use Starsnet\Project\Paraqon\App\Models\ConsignmentRequest;

class ConsignmentRequestController extends Controller
{
    public function createConsignmentRequest(Request $request): array
    {
        $data = array_merge($request->all(), ['requested_by_customer_id' => $this->customer()->id]);
        /** @var ?ConsignmentRequest $form */
        $form = ConsignmentRequest::create($data);

        return [
            'message' => 'Created New ConsignmentRequest successfully',
            '_id' => $form->id
        ];
    }
}
