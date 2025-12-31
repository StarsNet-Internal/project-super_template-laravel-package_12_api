<?php

namespace Starsnet\Project\Paraqon\App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

// Services
use Starsnet\Project\Paraqon\App\Services\TemporaryUserCleanupService;

class CleanupTemporaryUsersCommand extends Command
{
    protected $signature = 'paraqon:cleanup:temporary-users {date} {mysql_database} {mongodb_database}';
    protected $description = 'Clean up TEMP users on both MYSQL and MongoDB (Paraqon Package)';

    public function handle()
    {
        // Extract attributes from arguments
        $date = Carbon::parse($this->argument('date'));
        $mysqlDatabase = $this->argument('mysql_database');
        $mongodbDatabase = $this->argument('mongodb_database');

        Log::info("Started php artisan paraqon:cleanup:temporary-users {$date} {$mysqlDatabase} {$mongodbDatabase}");

        // Update the database configurations
        config(['database.connections.mysql.database' => $mysqlDatabase]);
        config(['database.connections.mongodb.database' => $mongodbDatabase]);

        // Measure time required for the task
        $startTime = microtime(true);

        // Cleanup
        $service = new TemporaryUserCleanupService($date);
        $service->cleanup();

        // Measure time required for the task
        $endTime = microtime(true);
        $elapsedTimeInSeconds = round(($endTime - $startTime), 2);

        Log::info("Finished php artisan paraqon:cleanup:temporary-users {$date} {$mysqlDatabase} {$mongodbDatabase} " . "({$elapsedTimeInSeconds} s)");

        return Command::SUCCESS;
    }
}
