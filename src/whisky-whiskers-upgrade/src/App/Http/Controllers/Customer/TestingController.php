<?php

namespace Starsnet\Project\WhiskyWhiskersUpgrade\App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Enums\Status;
use App\Enums\StoreType;
use Carbon\Carbon;

class TestingController extends Controller
{
    public function healthCheck()
    {
        return response()->json([
            'message' => 'OK from package/whisky-whiskers-upgrade'
        ], 200);
    }
}
