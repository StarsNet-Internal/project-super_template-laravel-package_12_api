<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Admin;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

// Models
use App\Models\Store;
use Starsnet\Project\Paraqon\App\Models\LiveBiddingEvent;

class LiveBiddingEventController extends Controller
{
    public function createEvent(Request $request): array
    {
        /** @var ?Store $store */
        $store = Store::find($request->route('store_id'));
        if (is_null($store)) abort(404, 'Store not found');

        // Create Event
        $data = array_merge($request->all(), ['store_id' => $request->route('store_id')]);
        LiveBiddingEvent::create($data);

        return ['message' => 'Success'];
    }
}
