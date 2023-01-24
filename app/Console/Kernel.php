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
        $schedule->command('orders:send:sexp')->everyThreeMinutes();

        $schedule->command('orders:send:erp')->everyThreeMinutes();

        $schedule->command('orders:update:tracking')->everyThreeMinutes();

        $schedule->command('orders:update:invoice')->everyThreeMinutes();

        $schedule->command('orders:update:shipment')->everyThreeMinutes();
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
