<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        Commands\CopyNewDates::class,
        Commands\CopyOverlayDates::class,
        Commands\DoImport::class,
        Commands\PopulateSkipList::class,
        Commands\ProcessJob::class,
        Commands\ProcessNextItem::class,
        Commands\MoveOutputFile::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('dcp:process-import')->everyMinute();
        $schedule->command('dcp:move_output_file')->hourly();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
