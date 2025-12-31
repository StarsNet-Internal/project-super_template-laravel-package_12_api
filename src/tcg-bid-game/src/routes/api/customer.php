<?php

// Default Imports
use Illuminate\Support\Facades\Route;

use Starsnet\Project\TcgBidGame\App\Http\Controllers\Customer\AdViewController;
use Starsnet\Project\TcgBidGame\App\Http\Controllers\Customer\CoinPackageController;
use Starsnet\Project\TcgBidGame\App\Http\Controllers\Customer\GameController;
use Starsnet\Project\TcgBidGame\App\Http\Controllers\Customer\OrderController;
use Starsnet\Project\TcgBidGame\App\Http\Controllers\Customer\ProductController;
use Starsnet\Project\TcgBidGame\App\Http\Controllers\Customer\TestingController;
use Starsnet\Project\TcgBidGame\App\Http\Controllers\Customer\TransactionController;
use Starsnet\Project\TcgBidGame\App\Http\Controllers\Customer\UserController;

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
| Health Check
|--------------------------------------------------------------------------
*/

Route::get('/health', [TestingController::class, 'healthCheck']);

/*
|--------------------------------------------------------------------------
| Games
|--------------------------------------------------------------------------
*/

Route::group(
    ['prefix' => 'games'],
    function () {
        Route::get('/', [GameController::class, 'getAllGames'])->middleware(['pagination']);
        Route::post('/{game_id}/start', [GameController::class, 'startGame'])->middleware(['auth:api']);
        Route::post('/{game_id}/end', [GameController::class, 'endGame'])->middleware(['auth:api']);
    }
);

/*
|--------------------------------------------------------------------------
| Products
|--------------------------------------------------------------------------
*/

Route::group(
    ['prefix' => 'products'],
    function () {
        Route::post('/', [ProductController::class, 'getAllProducts'])->middleware(['pagination']);
        Route::get('/{product_id}', [ProductController::class, 'getProductById']);
    }
);

/*
|--------------------------------------------------------------------------
| Orders
|--------------------------------------------------------------------------
*/

Route::group(
    ['prefix' => 'orders'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::post('/', [OrderController::class, 'getAllOrders'])->middleware(['pagination']);
                Route::get('/{order_id}', [OrderController::class, 'getOrderById']);
                Route::post('/create', [OrderController::class, 'createOrder']);
            }
        );
    }
);

/*
|--------------------------------------------------------------------------
| User
|--------------------------------------------------------------------------
*/

Route::group(
    ['prefix' => 'user'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::get('/currency', [UserController::class, 'getCurrency']);
                Route::get('/settings', [UserController::class, 'getSettings']);
                Route::put('/settings', [UserController::class, 'updateSettings']);
                Route::post('/onboarding', [UserController::class, 'completeOnboarding']);
            }
        );
    }
);

/*
|--------------------------------------------------------------------------
| Transactions
|--------------------------------------------------------------------------
*/

Route::group(
    ['prefix' => 'transactions'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::post('/', [TransactionController::class, 'getAllTransactions'])->middleware(['pagination']);
                Route::get('/{transaction_id}', [TransactionController::class, 'getTransactionById']);
            }
        );
    }
);

/*
|--------------------------------------------------------------------------
| Coin Packages
|--------------------------------------------------------------------------
*/

Route::group(
    ['prefix' => 'coin-packages'],
    function () {
        Route::get('/', [CoinPackageController::class, 'getAllCoinPackages'])->middleware(['pagination']);
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::post('/{package_id}/purchase', [CoinPackageController::class, 'purchaseCoinPackage']);
                Route::post('/verify-iap', [CoinPackageController::class, 'verifyIAPReceipt']);
            }
        );
    }
);

/*
|--------------------------------------------------------------------------
| Ad Views
|--------------------------------------------------------------------------
*/

Route::group(
    ['prefix' => 'ad-views'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::post('/', [AdViewController::class, 'watchAdOrGetHistory']);
            }
        );
    }
);
