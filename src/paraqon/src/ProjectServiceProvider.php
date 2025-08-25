<?php

namespace Starsnet\Project\Paraqon;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class ProjectServiceProvider extends ServiceProvider
{
    protected $namespace = 'Starsnet\Project\Paraqon\App\Http\Controllers';
    protected $routePrefix = 'paraqon';

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
                // Admin routes
                Route::prefix('admin/' . $this->routePrefix)
                    ->namespace('Admin')
                    ->group(__DIR__ . '/routes/api/admin.php');
                // Customer routes
                Route::prefix('customer/' . $this->routePrefix)
                    ->namespace('Customer')
                    ->group(__DIR__ . '/routes/api/customer.php');
            });
    }
}
