<?php

namespace Starsnet\Project\WhiskyWhiskers\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Enums
use App\Enums\Status;
use App\Enums\StoreType;

// Models
use App\Models\Store;

class AuctionController extends Controller
{
    public function getAllAuctions(Request $request): Collection
    {
        $statuses = (array) $request->input('status', Status::defaultStatuses());

        return Store::whereType(StoreType::OFFLINE->value)
            ->statuses($statuses)
            ->get();
    }

    public function getAuctionDetails(Request $request): Store
    {
        /** @var ?Store $store */
        $auction = Store::find($request->route('auction_id'));
        if (is_null($auction)) abort(404, 'Auction not found');
        if (!in_array($auction->status, [Status::ACTIVE->value, Status::ARCHIVED->value])) abort(404, 'Auction is not available for public');

        return $auction;
    }
}
