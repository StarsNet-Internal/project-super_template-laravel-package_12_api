<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Enums\Status;
use App\Enums\StoreType;
use App\Models\Content;
use App\Models\Customer;
use App\Models\CustomerGroup;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Starsnet\Project\Paraqon\App\Models\AuctionLot;

class TestingController extends Controller
{
    public function cart(Request $request)
    {
        $customerID = $request->customer_id;
        $storeID = $request->store_id;

        $customer = Customer::find($customerID);
        $store = Store::find($storeID);

        return response()->json($store);
    }

    public function healthCheck(Request $request)
    {
        // $paymentIntentID = 1234567890;
        // $url = env('PARAQON_STRIPE_BASE_URLSSS', 'https://socket.paraqon.starsnet.hk') . '/payment-intents/' . $paymentIntentID . '/cancel';
        // return [
        //     'message' => $url
        // ];

        return response()->json([
            'message' => 'OK from package/paraqon'
        ], 200);
    }

    public function callbackTest(Request $request)
    {
        return 'asdas';
        $content = Content::create($request->all());
    }
}
