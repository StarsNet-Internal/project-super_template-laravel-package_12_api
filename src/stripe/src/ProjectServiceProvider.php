<?php

namespace Starsnet\Project\Stripe;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class ProjectServiceProvider extends ServiceProvider
{
    protected $namespace = 'Starsnet\Project\Stripe\App\Http\Controllers';
    protected $routePrefix = 'stripe';

    public function register()
    {
        $this->loadRoutesFrom(__DIR__ . '/routes/api.php');
    }

    protected function loadRoutesFrom($path): void
    {
        Route::middleware('api')
            ->namespace($this->namespace)
            ->prefix('api')
            ->group(function () {
                // Customer routes
                Route::prefix('customer/' . $this->routePrefix)
                    ->namespace('Customer')
                    ->group(__DIR__ . '/routes/api/customer.php');
            });
    }
}
