<?php

// Default Imports
use Illuminate\Support\Facades\Route;

use Starsnet\Project\Auction\App\Http\Controllers\Customer\AuthenticationController;
use Starsnet\Project\Auction\App\Http\Controllers\Customer\AuctionRegistrationRequestController;
use Starsnet\Project\Auction\App\Http\Controllers\Customer\ConsignmentRequestController;
use Starsnet\Project\Auction\App\Http\Controllers\Customer\CreditCardController;
use Starsnet\Project\Auction\App\Http\Controllers\Customer\ReferralCodeController;
use Starsnet\Project\Auction\App\Http\Controllers\Customer\ShoppingCartController;
use Starsnet\Project\Auction\App\Http\Controllers\Customer\SiteMapController;
use Starsnet\Project\Auction\App\Http\Controllers\Customer\TestingController;

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

/*
|--------------------------------------------------------------------------
| Product related
|--------------------------------------------------------------------------
*/

Route::group(
    ['prefix' => 'auth'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::post('/migrate', [AuthenticationController::class, 'migrateToRegistered']);
            }
        );
    }
);

Route::group(
    ['prefix' => 'auction-registration-requests'],
    function () {
        Route::put('/{auction_registration_request_id}/details', [AuctionRegistrationRequestController::class, 'updateAuctionRegistrationRequest'])->middleware(['auth:api']);
    }
);


Route::group(
    ['prefix' => 'credit-cards'],
    function () {
        Route::post('/bind', [CreditCardController::class, 'bindCard'])->middleware(['auth:api']);
        Route::get('/validate', [CreditCardController::class, 'validateCard'])->middleware(['auth:api']);
    }
);

Route::group(
    ['prefix' => 'consignments'],
    function () {
        Route::group(['middleware' => 'auth:api'],  function () {
            Route::post('/', [ConsignmentRequestController::class, 'createConsignmentRequest']);
        });
    }
);

Route::group(
    ['prefix' => 'referral-codes'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::get('/details', [ReferralCodeController::class, 'getReferralCodeDetails']);
                Route::get('/histories/all', [ReferralCodeController::class, 'getAllReferralCodeHistories'])->middleware(['pagination']);
            }
        );
    }
);

Route::group(
    ['prefix' => 'sitemap'],
    function () {
        Route::get('/auctions/all', [SiteMapController::class, 'getAllAuctions'])->middleware(['pagination']);
        Route::get('/auctions/{store_id}/products/all', [SiteMapController::class, 'filterAuctionProductsByCategories'])->middleware(['pagination']);
        Route::get('/auction-lots/{auction_lot_id}/details', [SiteMapController::class, 'getAuctionLotDetails']);
    }
);

/*
|--------------------------------------------------------------------------
| Store-scoped (shopping cart, etc.)
|--------------------------------------------------------------------------
*/

Route::group(
    ['prefix' => '/stores/{store_id}/'],
    function () {
        Route::group(
            ['prefix' => 'shopping-cart'],
            function () {
                Route::group(['middleware' => 'auth:api'], function () {
                    Route::post('/all', [ShoppingCartController::class, 'getAll']);
                    Route::post('/main-store/checkout', [ShoppingCartController::class, 'checkOutMainStore']);
                });
            }
        );
    }
);
