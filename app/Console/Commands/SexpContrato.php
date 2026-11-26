<?php

namespace App\Console\Commands;

use App\Services\Sexp;
use Illuminate\Console\Command;
use SoapClient;
use Throwable;

class SexpContrato extends Command
{
    protected $signature = 'sexp:contrato';
    protected $description = 'Consulta o WSDL e mostra os tipos fiscais de informarNF.';

    public function handle(): int
    {
        if (!class_exists(SoapClient::class)) {
            $this->error('A extensão SOAP do PHP está indisponível.');
            return self::FAILURE;
        }

        try {
            $client = new SoapClient(Sexp::WSDL, [
                'cache_wsdl' => WSDL_CACHE_NONE,
                'stream_context' => stream_context_create(['http' => ['timeout' => 10]]),
            ]);
            $types = $client->__getTypes();
        } catch (Throwable $error) {
            $this->error('Falha ao ler o contrato WSDL do SEXP.');
            return self::FAILURE;
        }

        foreach ($types as $type) {
            if (stripos($type, 'chaveNfe') !== false || stripos($type, 'protocoloNfe') !== false) {
                $this->line($type);
                $found = true;
            }
        }
        if (empty($found)) {
            $this->warn('Tipo da NF não identificado. Tipos relacionados:');
            foreach ($types as $type) {
                if (stripos($type, 'informarNF') !== false || stripos($type, 'notaFiscal') !== false) {
                    $this->line($type);
                }
            }
            return self::FAILURE;
        }
        return self::SUCCESS;
    }
}
