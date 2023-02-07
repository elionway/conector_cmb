<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('orders:send:sexp')->everyTenMinutes();

        $schedule->command('orders:send:erp')->everyTenMinutes();

        $schedule->command('orders:update:tracking')->everyTenMinutes();

        $schedule->command('orders:update:invoice')->everyTenMinutes();

        $schedule->command('orders:update:shipment')->everyTenMinutes();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
