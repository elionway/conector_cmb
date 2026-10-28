<?php

namespace App\Console\Commands;

use App\Services\ConnectorAlertReporter;
use Illuminate\Console\Command;

class FlushConnectorAlerts extends Command
{
    protected $signature = 'connector:alerts:flush';
    protected $description = 'Retry pending connector alerts without processing orders';

    public function handle(ConnectorAlertReporter $reporter): int
    {
        $result = $reporter->flush(20);
        $this->line(json_encode($result));
        return $result['failed'] > 0 ? 1 : 0;
    }
}
