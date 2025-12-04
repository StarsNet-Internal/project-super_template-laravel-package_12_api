<?php

// Default Imports
use Illuminate\Support\Facades\Route;

use Starsnet\Project\Auction\App\Http\Controllers\Admin\ProductController;
use Starsnet\Project\Auction\App\Http\Controllers\Admin\ReferralCodeController;
use Starsnet\Project\Auction\App\Http\Controllers\Admin\ServiceController;
use Starsnet\Project\Auction\App\Http\Controllers\Admin\TestingController;

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

/*
|--------------------------------------------------------------------------
| Development Uses
|--------------------------------------------------------------------------
*/

Route::group(
    ['prefix' => 'tests'],
    function () {
        Route::get('/health-check', [TestingController::class, 'healthCheck']);
    }
);

Route::group(
    ['prefix' => 'products'],
    function () {
        Route::put('/mass-update', [ProductController::class, 'massUpdateProducts']);
    }
);

Route::group(
    ['prefix' => 'services'],
    function () {
        Route::post('/payment/callback', [ServiceController::class, 'paymentCallback']);
        Route::post('/auction-orders/create', [ServiceController::class, 'createAuctionOrder']);
    }
);

Route::group(
    ['prefix' => 'referral-codes'],
    function () {
        Route::post('/mass-generate', [ReferralCodeController::class, 'massGenerateReferralCodes']);
    }
);
