<?php

namespace App\Console;

use App\Console\Commands\CopyMetrics;
use App\Console\Commands\CopyOverlayDates;
use App\Console\Commands\DoImport;
use App\Console\Commands\ImportDsdSkip;
use App\Console\Commands\PopulateSkipList;
use App\Console\Commands\ProcessNextItem;
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
        DoImport::class,
        ProcessNextItem::class,
        PopulateSkipList::class,
        CopyMetrics::class,
        CopyOverlayDates::class,
        ImportDsdSkip::class,
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
        // $schedule->command('dcp:copy-metrics')->daily();
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
