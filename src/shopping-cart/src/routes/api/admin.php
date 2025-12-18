<?php

// Default Imports
use Illuminate\Support\Facades\Route;

use Starsnet\Project\ShoppingCart\App\Http\Controllers\Admin\CheckoutController;
use Starsnet\Project\ShoppingCart\App\Http\Controllers\Admin\ShoppingCartController;

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
    ['prefix' => 'stores'],
    function () {
        Route::group(
            ['middleware' => 'auth:api'],
            function () {
                Route::post('/{store_id}/checkouts', [CheckoutController::class, 'checkout']);
                Route::post('/{store_id}/shopping-cart/all', [ShoppingCartController::class, 'getAll']);
            }
        );
    }
);
