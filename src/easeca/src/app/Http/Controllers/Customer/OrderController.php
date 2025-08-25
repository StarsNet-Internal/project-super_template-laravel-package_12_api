<?php

namespace StarsNet\Project\Easeca\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Support\Collection;

// Models
use App\Models\Alias;
use App\Models\Order;
use App\Models\Store;

class OrderController extends Controller
{
    public function getAllOfflineOrders()
    {
        $account = $this->account();
        $store = Store::find($account->store_id)
            ?? Store::find(Alias::getValue($account->store_id));

        /** @var Collection $orders */
        return Order::where('store_id', $store->id)
            ->where('customer_id', $this->customer()->id)
            ->get()
            ->makeHidden(['cart_items', 'gift_items']);
    }
}
