<?php

namespace Starsnet\Project\WhiskyWhiskers\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Enums
use App\Enums\ReplyStatus;
use App\Enums\Status;

// Models
use App\Models\Product;
use Illuminate\Support\Arr;
use Starsnet\Project\WhiskyWhiskers\App\Models\AuctionLot;
use Starsnet\Project\WhiskyWhiskers\App\Models\AuctionRequest;
use Starsnet\Project\WhiskyWhiskers\App\Models\BidHistory;

class AuctionRequestController extends Controller
{
    public function getAllAuctionRequests(Request $request): Collection
    {
        $filterParams = Arr::except($request->query(), ['per_page', 'page', 'sort_by', 'sort_order']);

        $query = AuctionRequest::where('status', '!=', Status::DELETED->value);
        foreach ($filterParams as $key => $value) {
            $query = $query->where($key, $value);
        }

        return $query->with([
            'requestedCustomer',
            'approvedAccount',
            'product',
        ])
            ->get();
    }

    public function updateAuctionRequests(Request $request): array
    {
        /** @var ?AuctionRequest $form */
        $form = AuctionRequest::find($request->route('id'));
        if (is_null($form)) abort(404, 'AuctionRequest not found');

        $form->update($request->all());

        return [
            'message' => 'Updated AuctionRequest successfully',
            '_id' => $form->_id,
        ];
    }

    public function approveAuctionRequest(Request $request): array
    {
        // Update reply_status
        $form = AuctionRequest::find($request->route('id'));
        $form->update(['reply_status' => $request->reply_status]);
        $wasInAuction = $form->is_in_auction;

        // Update form attributes
        $formAttributes = [
            "requested_by_account_id" => $request->requested_by_account_id,
            "starting_bid" => $request->starting_bid,
            "reserve_price" => $request->reserve_price,
        ];
        $formAttributes = array_filter($formAttributes, function ($value) {
            return !is_null($value) && $value != "";
        });
        $form->update($formAttributes);

        // Update Product listing_status
        $auctionLotId = null;
        $product = Product::find($form->product_id);

        if ($request->reply_status == ReplyStatus::APPROVED->value) {
            $product->update(['listing_status' => 'LISTED_IN_AUCTION']);
            $form->update(['is_in_auction' => true]);

            // Create auction_lot
            $auctionLotFields = [
                'auction_request_id' => $form->_id,
                'owned_by_customer_id' => $form->requested_by_customer_id,
                'product_id' => $form->product_id,
                'product_variant_id' => $form->product_variant_id,
                'store_id' => $form->store_id,
                'starting_price' => $form->starting_bid ?? 0,
                'current_bid' => $form->starting_bid ?? 0,
                'reserve_price' => $form->reserve_price ?? 0,
            ];

            $auctionLot = AuctionLot::create($auctionLotFields);
            $auctionLotId = $auctionLot->_id;

            BidHistory::create([
                'auction_lot_id' => $auctionLotId,
                'current_bid' => $auctionLot->starting_price,
                'histories' => []
            ]);
        } else if ($request->reply_status == ReplyStatus::REJECTED->value) {
            $product->update(['listing_status' => 'AVAILABLE']);
            $form->update(['is_in_auction' => false]);

            if ($wasInAuction) {
                AuctionLot::where('auction_request_id', $form->_id)->update([
                    'status' => Status::DELETED->value,
                    'is_disabled' => true
                ]);
            }
        }

        return [
            'message' => 'Updated AuctionRequest successfully',
            '_id' => $form->_id,
            'auction_lot_id' => $auctionLotId
        ];
    }

    public function updateAuctionLotDetailsByAuctionRequest(Request $request): array
    {
        // Auction Request ID
        $auctionRequestID = $request->route('id');
        $form = AuctionRequest::find($auctionRequestID);
        if (is_null($form)) abort(404, 'AuctionRequest not found');

        $auctionLot = AuctionLot::where('auction_request_id', $form->_id)->latest()->first();
        if (is_null($auctionLot)) abort(404, 'AuctionLot not found');

        // Update AuctionRequest
        $updateAttributes = [
            'starting_bid' => $request->starting_price,
            'reserve_price' => $request->reserve_price,
        ];
        $updateAttributes = array_filter($updateAttributes, function ($value) {
            return !is_null($value) && $value != "";
        });
        $form->update($updateAttributes);

        // Update AuctionLot
        $updateAttributes = [
            'starting_price' => $request->starting_price,
            'current_bid' => $request->starting_price,
            'reserve_price' => $request->reserve_price,
        ];
        $updateAttributes = array_filter($updateAttributes, function ($value) {
            return !is_null($value) && $value != "";
        });
        $auctionLot->update($updateAttributes);

        return [
            'message' => 'Updated AuctionLot successfully',
            'auction_request_id' => $auctionRequestID,
            'auction_lot_id' => $auctionLot->_id
        ];
    }
}
