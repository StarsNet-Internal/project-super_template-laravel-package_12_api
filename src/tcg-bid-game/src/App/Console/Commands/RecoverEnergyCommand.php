<?php

namespace Starsnet\Project\TcgBidGame\App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Starsnet\Project\TcgBidGame\App\Models\GameUser;

class RecoverEnergyCommand extends Command
{
    protected $signature = 'game:recover-energy {mongodb_database}';
    protected $description = 'Recover energy for all game users based on time intervals';

    public function handle()
    {
        // Extract attributes from arguments
        $mongodbDatabase = $this->argument('mongodb_database');

        Log::info("Started php artisan game:recover-energy {$mongodbDatabase}");

        // Update the database configuration
        config(['database.connections.mongodb.database' => $mongodbDatabase]);

        // Measure time required for the task
        $startTime = microtime(true);

        $this->info('Starting energy recovery process...');
        
        $users = GameUser::all();
        $totalRecovered = 0;
        $usersProcessed = 0;
        $usersWithRecovery = 0;

        foreach ($users as $user) {
            $recovered = $user->checkAndRecoverEnergy();
            if ($recovered > 0) {
                $totalRecovered += $recovered;
                $usersWithRecovery++;
            }
            $usersProcessed++;
        }

        // Measure time required for the task
        $endTime = microtime(true);
        $elapsedTimeInSeconds = round(($endTime - $startTime), 2);

        $this->info("Energy recovery completed. Processed {$usersProcessed} users, {$usersWithRecovery} users recovered energy, {$totalRecovered} total energy recovered.");
        Log::info("Finished php artisan game:recover-energy {$mongodbDatabase} - Processed {$usersProcessed} users, {$usersWithRecovery} users recovered energy, {$totalRecovered} total energy recovered ({$elapsedTimeInSeconds} s)");

        return Command::SUCCESS;
    }
}

