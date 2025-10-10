<?php

// Default Imports
use Illuminate\Support\Facades\Route;

use Starsnet\Project\Rmhc\App\Http\Controllers\Admin\AuctionLotController;
use Starsnet\Project\Rmhc\App\Http\Controllers\Admin\ArticleController;
use Starsnet\Project\Rmhc\App\Http\Controllers\Admin\BatchPaymentController;
use Starsnet\Project\Rmhc\App\Http\Controllers\Admin\ServiceController;
use Starsnet\Project\Rmhc\App\Http\Controllers\Admin\TestingController;

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
        $defaultController = TestingController::class;

        Route::get('/health-check', [$defaultController, 'healthCheck']);
    }
);

/*
|--------------------------------------------------------------------------
| Development Uses
|--------------------------------------------------------------------------
*/

Route::group(
    ['prefix' => 'auction-lots'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::get('/all', [AuctionLotController::class, 'getAllAuctionLots'])->middleware(['pagination']);
            }
        );
    }
);

// Articles
Route::group(
    ['prefix' => 'articles'],
    function () {
        Route::put('/mass-update', [ArticleController::class, 'massUpdateArticles']);
    }
);

// Orders (Donation and Batch Payment related)
Route::group(
    ['prefix' => 'batch-payments'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::get('/all', [BatchPaymentController::class, 'getAllBatchPayments'])->middleware(['pagination']);
                Route::get('/{id}/details', [BatchPaymentController::class, 'getBatchPaymentDetails']);
                Route::put('/{id}/details', [BatchPaymentController::class, 'updateBatchPayment']);
                Route::put('/{id}/approve', [BatchPaymentController::class, 'approveBatchPayment']);
            }
        );
    }
);

// Watchlist
Route::group(
    ['prefix' => 'services'],
    function () {
        Route::post('/payment/callback', [ServiceController::class, 'paymentCallback']);
        Route::post('/auctions/{store_id}/orders/create', [ServiceController::class, 'generateAuctionOrders']);
        Route::post('/batch-payments/charge', [ServiceController::class, 'createBatchPaymentAndCharge']);
    }
);
