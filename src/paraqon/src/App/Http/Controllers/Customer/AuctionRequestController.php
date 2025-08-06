<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;

// Enums
use App\Enums\ReplyStatus;
use App\Enums\Status;
use App\Enums\StoreType;

// Models
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Support\Collection;
use Starsnet\Project\Paraqon\App\Models\AuctionLot;
use Starsnet\Project\Paraqon\App\Models\AuctionRequest;
use Starsnet\Project\Paraqon\App\Models\BidHistory;

class AuctionRequestController extends Controller
{
    public function getAllAuctionRequests(): Collection
    {
        return AuctionRequest::where('requested_by_customer_id', $this->customer()->id)
            ->with(['store', 'product'])
            ->get();
    }

    public function createAuctionRequest(Request $request): array
    {
        $now = now();

        /** @var ?ProductVariant $variant */
        $variant = ProductVariant::find($request->product_variant_id);
        if (is_null($variant)) abort(404, 'ProductVariant not found');

        /** @var Product $product */
        $product = $variant->product;
        $customer = $this->customer();

        if ($product->owned_by_customer_id != $customer->_id) abort(403, 'This product does not belong to the customer');
        if ($product->listing_status != "AVAILABLE") abort(403, 'This product can not apply for auction');

        /** @var Store $store */
        $store = Store::find($request->store_id);
        if (is_null($store)) abort(404, 'Auction not found');

        // Create AuctionRequest
        $updateAuctionRequestFields = [
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'starting_bid' => $request->input('starting_bid', 0),
            'reserve_price' => $request->input('reserve_price', 0),
        ];
        $form = AuctionRequest::create($updateAuctionRequestFields);

        // Check if auto-approve needed
        $upcomingStores = Store::where('type', StoreType::OFFLINE->value)
            ->statuses([Status::ARCHIVED->value, Status::ACTIVE->value])
            ->orderBy('start_datetime')
            ->get();

        $nearestUpcomingStore = null;
        foreach ($upcomingStores as $store) {
            $startTime = $store->start_datetime;
            $startTime = Carbon::parse($startTime);
            if ($now < $startTime) {
                $nearestUpcomingStore = $store;
                break;
            }
        }

        if (!is_null($nearestUpcomingStore) && $nearestUpcomingStore->_id == $store->id) {
            $form->update([
                'reply_status' => ReplyStatus::APPROVED->value,
                'is_in_auction' => true
            ]);

            $updateProductFields = ['listing_status' => 'LISTED_IN_AUCTION'];
            $product->update($updateProductFields);

            // Create auction_lot
            $auctionLotFields = [
                'auction_request_id' => $form->_id,
                'owned_by_customer_id' => $customer->_id,
                'product_id' => $form->product_id,
                'product_variant_id' => $form->product_variant_id,
                'store_id' => $form->store_id,
                'starting_price' => $form->starting_bid ?? 0,
                'current_bid' => $form->starting_bid ?? 0,
                'reserve_price' => $form->reserve_price ?? 0,
            ];
            $auctionLot = AuctionLot::create($auctionLotFields);

            BidHistory::create([
                'auction_lot_id' => $auctionLot->_id,
                'current_bid' => $auctionLot->starting_price,
                'histories' => []
            ]);
        } else {
            $updateProductFields = ['listing_status' => 'PENDING_FOR_AUCTION'];
            $product->update($updateProductFields);
        }

        return [
            'message' => 'Created New AuctionRequest successfully',
            '_id' => $form->id
        ];
    }
}
