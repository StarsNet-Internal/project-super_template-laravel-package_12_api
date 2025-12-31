<?php

namespace Starsnet\Project\TcgBidGame;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class ProjectServiceProvider extends ServiceProvider
{
    protected $namespace = 'Starsnet\Project\TcgBidGame\App\Http\Controllers';
    protected $routePrefix = 'tcg-bid-game';

    public function register()
    {
        $this->loadRoutesFrom(__DIR__ . '/routes/api.php');

        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Starsnet\Project\TcgBidGame\App\Console\Commands\RecoverEnergyCommand::class,
            ]);
        }
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
                Route::namespace('Customer')
                    ->group(__DIR__ . '/routes/api/customer.php');
            });
    }
}
