<?php

namespace App\Console\Commands;

use App\Services\ConnectorAlertReporter;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory;

class TestConnectorAlert extends Command
{
    protected $signature = 'connector:alerts:test';
    protected $description = 'Send a synthetic notification without processing orders';

    public function handle(): int
    {
        $options = config('connector_alerts', []);
        $url = parse_url((string) ($options['url'] ?? ''));
        if (($options['enabled'] ?? false) !== true
            || strlen((string) ($options['secret'] ?? '')) < 32
            || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])
            || !in_array($options['environment'] ?? '', ['production', 'staging', 'local', 'testing'], true)) {
            $this->error('Configure e habilite CONNECTOR_ALERTS antes do teste.');
            return 1;
        }

        // A new identity and private queue prevent interaction with real incidents.
        $options['connector'] = 'cmb-test-' . bin2hex(random_bytes(8));
        $directory = storage_path('framework/connector-alerts-test/' . $options['connector']);
        $this->line('Teste sintetico: envia e-mail aos destinatarios configurados no CMB.');
        $this->line('Identificador: ' . $options['connector']);
        $reporter = new ConnectorAlertReporter($options, $directory, app(Factory::class));
        $reporter->setCommand('orders:send:sexp');
        $reporter->report(new \RuntimeException('Synthetic notification test'), 'runtime');

        $state = is_file($directory . '/state.json')
            ? json_decode(file_get_contents($directory . '/state.json'), true) : null;
        if (!is_array($state) || !isset($state['pending']) || !is_array($state['pending'])) {
            $this->error('Nao foi possivel verificar a fila privada do teste.');
            return 1;
        }
        if (count($state['pending']) > 0) {
            $this->error('Alerta nao confirmado pelo CMB. Verifique HTTPS, chave, whitelist e SMTP.');
            $this->line('Fila de teste preservada em: ' . $directory);
            return 1;
        }
        $this->info('Alerta aceito pelo CMB. Confira a caixa de entrada e o spam; ACK nao comprova entrega.');
        return 0;
    }
}
