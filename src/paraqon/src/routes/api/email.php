<?php

// Default Imports
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Starsnet\Project\Paraqon\App\Http\Controllers\Email\TestingController;
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
        Route::get('/debug-views', [TestingController::class, 'debugViews']);
    }
);

Route::post('/header', function (Request $request) {
    $namespace = 'paraqon';
    $bladeName = 'app';

    $components = $request->components;
    foreach ($components as &$component) {
        $component['name'] = strtolower(preg_replace('/(?<!^)([A-Z])/', '-$1', $component['name']));
    }

    return view($namespace . '::' . $bladeName, ['components' => $components]);
});
