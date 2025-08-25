<?php

// Default Imports

use Illuminate\Support\Facades\Route;
use Starsnet\Project\Stripe\App\Http\Controllers\Customer\OrderController;
use Starsnet\Project\Stripe\App\Http\Controllers\Customer\TestingController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::group(
    ['prefix' => 'tests'],
    function () {
        Route::get('/health-check', [TestingController::class, 'healthCheck']);
    }
);

Route::group(
    ['prefix' => 'stores/{store_id}'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::post('/checkout', [OrderController::class, 'checkout']);
            }
        );
    }
);

Route::group(
    ['prefix' => 'payments'],
    function () {
        Route::post('/callback', [OrderController::class, 'onlinePaymentCallback']);
    }
);
