<?php

namespace App\Console\Commands;

use App\Services\Sexp;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

class InspectSexp extends Command
{
    protected $signature = 'sexp:consulta {pedido}';
    protected $description = 'Consulta uma remessa e exibe o HTTP e o corpo da resposta SOAP.';

    public function handle(Sexp $sexp): int
    {
        $id = (string)$this->argument('pedido');
        if (!preg_match('/^[0-9]{1,9}$/D', $id) || (int)$id === 0) {
            $this->error('Informe um pedido positivo de até 9 dígitos.');
            return self::FAILURE;
        }

        try {
            $response = $sexp->inspectOrder((int)$id);
        } catch (\Throwable $error) {
            $this->error('Consulta ao SEXP interrompida (' . class_basename($error) . ').');
            return self::FAILURE;
        }

        $this->line('HTTP ' . $response->status());
        $body = $response->body();
        if (trim($body) === '') {
            $this->warn('Resposta sem corpo.');
            return self::FAILURE;
        }

        $this->output->writeln($body, OutputInterface::OUTPUT_RAW);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if (!$response->successful() || $xml === false || $xml->xpath('/*[local-name()="Envelope"]/*[local-name()="Body"]/*[local-name()="Fault"]')) {
            return self::FAILURE;
        }
        return self::SUCCESS;
    }
}
