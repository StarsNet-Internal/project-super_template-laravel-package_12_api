<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// Enums
use App\Enums\Status;
use App\Enums\StoreType;
use App\Enums\ReplyStatus;

// Models
use App\Models\Store;
use Illuminate\Support\Collection;
use Starsnet\Project\Paraqon\App\Models\AuctionRegistrationRequest;
use Starsnet\Project\Paraqon\App\Models\Deposit;
use Starsnet\Project\Paraqon\App\Models\WatchlistItem;

class AuctionController extends Controller
{
    public function getAllAuctions(Request $request): Collection
    {
        // Extract attributes from $request
        $statuses = (array) $request->input('status', [Status::ACTIVE->value, Status::ARCHIVED->value]);

        $customer = $this->customer();

        /** @var Collection $auctions */
        $auctions = Store::where('type', StoreType::OFFLINE->value)
            ->statuses($statuses)
            ->get();

        // Append keys
        $watchingAuctionIDs = WatchlistItem::where('customer_id', $customer->id)
            ->where('item_type', 'store')
            ->pluck('item_id')
            ->values()
            ->all();

        foreach ($auctions as $auction) {
            $storeID = $auction->id;
            $auction->is_watching = in_array($storeID, $watchingAuctionIDs);

            /** @var ?AuctionRegistrationRequest $auctionRegistrationRequest */
            $auctionRegistrationRequest = AuctionRegistrationRequest::where(
                'requested_by_customer_id',
                $customer->id
            )
                ->where('store_id', $auction->id)
                ->first();

            $auction->auction_registration_request = null;
            $auction->is_registered = false;

            if (
                !is_null($auctionRegistrationRequest)
                && in_array($auctionRegistrationRequest->reply_status, [ReplyStatus::APPROVED->value, ReplyStatus::PENDING->value])
                && $auctionRegistrationRequest->status === Status::ACTIVE->value
            ) {
                $auction->is_registered = $auctionRegistrationRequest->reply_status == ReplyStatus::APPROVED->value;
                $auction->auction_registration_request = $auctionRegistrationRequest;
            }

            $auction->deposits = Deposit::where('requested_by_customer_id', $customer->id)
                ->where('status', '!=', Status::DELETED->value)
                ->whereHas('auctionRegistrationRequest', function ($query) use ($storeID) {
                    $query->where('store_id', $storeID);
                })
                ->latest()
                ->get();
        }

        return $auctions;
    }

    public function getAuctionDetails(Request $request): Store
    {
        /** @var ?Store $store */
        $auction = Store::find($request->route('auction_id'));
        if (is_null($auction)) abort(404, 'Auction not found');
        if (!in_array($auction->status, [Status::ACTIVE->value, Status::ARCHIVED->value])) {
            abort(404, 'Auction is not available for public');
        }

        // get Registration Status
        $customer = $this->customer();

        $auctionRegistrationRequest = AuctionRegistrationRequest::where(
            'requested_by_customer_id',
            $customer->id
        )
            ->where('store_id', $auction->id)
            ->first();

        $auction->auction_registration_request = null;
        $auction->is_registered = false;

        if (
            !is_null($auctionRegistrationRequest)
            && in_array($auctionRegistrationRequest->reply_status, [ReplyStatus::APPROVED->value, ReplyStatus::PENDING->value])
            && $auctionRegistrationRequest->status === Status::ACTIVE->value
        ) {
            $auction->is_registered = $auctionRegistrationRequest->reply_status == ReplyStatus::APPROVED->value;
            $auction->auction_registration_request = $auctionRegistrationRequest;
        }

        // Get Watching Stores
        $watchingAuctionIDs = WatchlistItem::where('customer_id', $customer->id)
            ->where('item_type', 'store')
            ->get()
            ->pluck('item_id')
            ->all();

        $auction->is_watching = in_array($auction->id, $watchingAuctionIDs);

        $auction->deposits = Deposit::where('requested_by_customer_id', $customer->id)
            ->where('status', '!=', Status::DELETED->value)
            ->whereHas('auctionRegistrationRequest', function ($query) use ($auction) {
                $query->where('store_id', $auction->id);
            })
            ->latest()
            ->get();

        return $auction;
    }

    public function getAllPaddles(Request $request): Collection
    {
        return AuctionRegistrationRequest::where('store_id', $request->route('auction_id'))
            ->whereNotNull('paddle_id')
            ->where('status', '!=', Status::DELETED->value)
            ->get()
            ->map(
                function ($item) {
                    return [
                        'customer_id' => $item['requested_by_customer_id'],
                        'paddle_id' => $item['paddle_id']
                    ];
                }
            );
    }
}
