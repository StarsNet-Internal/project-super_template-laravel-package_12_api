<?php

namespace Starsnet\Project\Paraqon\App\Http\Controllers\Customer;

// Laravel built-in
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TestingController extends Controller
{
    public function healthCheck(Request $request)
    {
        return [
            'message' => 'OK from package/paraqon'
        ];
    }
}
