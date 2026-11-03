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
       $schedule->command('orders:send:sexp')->everyTenMinutes()->when(fn () => !app(\App\Services\ConnectorAudit::class)->enabled());

       $schedule->command('orders:send:erp')->everyTenMinutes()->when(fn () => !app(\App\Services\ConnectorAudit::class)->enabled());

       $schedule->command('orders:update:tracking')->everyTenMinutes()->when(fn () => !app(\App\Services\ConnectorAudit::class)->enabled());

       $schedule->command('orders:update:invoice')->everyTenMinutes()->when(fn () => !app(\App\Services\ConnectorAudit::class)->enabled());

       $schedule->command('orders:update:shipment')->everyTenMinutes()->when(fn () => !app(\App\Services\ConnectorAudit::class)->enabled());
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
