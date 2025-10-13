<?php

// Default Imports
use Illuminate\Support\Facades\Route;
use Starsnet\Project\Rmhc\App\Http\Controllers\Customer\AuctionLotController;
use Starsnet\Project\Rmhc\App\Http\Controllers\Customer\AuthenticationController;
use Starsnet\Project\Rmhc\App\Http\Controllers\Customer\BidController;
use Starsnet\Project\Rmhc\App\Http\Controllers\Customer\CreditCardController;
use Starsnet\Project\Rmhc\App\Http\Controllers\Customer\CustomerController;
use Starsnet\Project\Rmhc\App\Http\Controllers\Customer\OrderController;
use Starsnet\Project\Rmhc\App\Http\Controllers\Customer\ProductManagementController;
use Starsnet\Project\Rmhc\App\Http\Controllers\Customer\TestingController;
use Starsnet\Project\Rmhc\App\Http\Controllers\Customer\WatchlistItemController;

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

// AuctionLots
Route::group(
    ['prefix' => 'auction-lots'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::get('/{auction_lot_id}/details', [AuctionLotController::class, 'getAuctionLotDetails']);
                Route::get('/participated/all', [AuctionLotController::class, 'getAllParticipatedAuctionLots'])->middleware(['pagination']);
                Route::post('/{auction_lot_id}/bids', [AuctionLotController::class, 'createMaximumBid']);
            }
        );
    }
);

// Bids
Route::group(
    ['prefix' => 'bids'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::get('/all', [BidController::class, 'getAllBids']);
            }
        );
    }
);


Route::group(
    ['prefix' => 'credit-cards'],
    function () {
        Route::post('/bind', [CreditCardController::class, 'bindCard'])->middleware(['auth:api']);
        // Route::get('/validate', [CreditCardController::class, 'validateCard'])->middleware(['auth:api']);
    }
);

Route::group(
    ['prefix' => 'customers'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::get('/all', [CustomerController::class, 'getAllCustomers'])->middleware(['pagination']);
            }
        );
    }
);
// Orders (Donation and Batch Payment related)
Route::group(
    ['prefix' => 'orders'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::post('/donate', [OrderController::class, 'createDonationOrder']);
                Route::get('/batch/preview', [OrderController::class, 'getOrdersByBatchPreview']);
                Route::post('/batch', [OrderController::class, 'payOrdersByBatch']);
                Route::get('/all', [OrderController::class, 'getAllOrders'])->middleware(['pagination']);
            }
        );
    }
);

// Store
Route::group(
    ['prefix' => '/stores/{store_id}/'],
    function () {
        // Products/AuctionLots
        Route::group(
            ['prefix' => 'product-management'],
            function () {
                Route::group(
                    ['middleware' => 'auth:api'],
                    function () {
                        Route::get('/products/filter/v2', [ProductManagementController::class, 'filterAuctionProductsByCategoriesV2'])->middleware(['pagination']);
                    }
                );
            }
        );
    }
);

// Watchlist
Route::group(
    ['prefix' => 'watchlist'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::get('/auction-lots', [WatchlistItemController::class, 'getWatchedAuctionLots'])->middleware(['pagination']);
            }
        );
    }
);
