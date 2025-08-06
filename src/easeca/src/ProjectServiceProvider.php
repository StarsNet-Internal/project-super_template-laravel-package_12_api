<?php

namespace StarsNet\Project\Easeca;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class ProjectServiceProvider extends ServiceProvider
{
    protected $namespace = 'StarsNet\Project\Easeca\App\Http\Controllers';
    protected $routePrefix = 'easeca';

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
