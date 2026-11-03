<?php

namespace App\Console\Commands;

use App\Services\ConnectorAudit;
use Illuminate\Console\Command;

class ConnectorAuditMode extends Command
{
    protected $signature = 'connector:audit {action=status : on, off or status}';
    protected $description = 'Control connector audit mode and automatic synchronization';

    public function handle(ConnectorAudit $audit): int
    {
        $action = $this->argument('action');
        if (!in_array($action, ['on','off','status'], true)) { $this->error('Use on, off ou status.'); return 1; }
        if ($action !== 'status') { $audit->setEnabled($action === 'on'); }
        $this->info($audit->enabled() ? 'AUDITORIA ATIVA: sincronizacao exige --audit-manual.' : 'AUDITORIA DESATIVADA: agendas normais liberadas.');
        $this->line('Logs privados UTC: ' . $audit->directory() . '/audit-AAAA-MM-DD.jsonl');
        return 0;
    }
}
