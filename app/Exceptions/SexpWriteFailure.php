<?php

namespace App\Exceptions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

class SexpWriteFailure extends RuntimeException
{
    public static function describe(Throwable $error): string
    {
        if ($error instanceof self) {
            return $error->getMessage();
        }
        if ($error instanceof ConnectionException) {
            return 'http=indisponivel motivo=falha_de_conexao';
        }
        return 'motivo=excecao_local tipo=' . get_class($error);
    }

    public static function check(Response $response, string $expected): void
    {
        $http = 'http=' . $response->status();
        $xml = simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $body = '/*[local-name()="Envelope"]/*[local-name()="Body"]';
        if ($xml === false) {
            throw new self($http . ' motivo=xml_ausente_ou_invalido');
        }
        $faults = $xml->xpath($body . '/*[local-name()="Fault"]');
        if ($faults) {
            $fault = $faults[0];
            $codes = $fault->xpath('./*[local-name()="faultcode"] | ./*[local-name()="Code"]/*[local-name()="Value"]');
            $reasons = $fault->xpath('./*[local-name()="faultstring"] | ./*[local-name()="Reason"]/*[local-name()="Text"]');
            throw new self($http . ' motivo=soap_fault faultcode=' . self::safeText((string)($codes[0] ?? ''))
                . ' faultstring=' . self::safeText((string)($reasons[0] ?? '')));
        }
        if (!$response->successful()) {
            throw new self($http . ' motivo=erro_http');
        }
        if (!$xml->xpath($body . '/*[local-name()="' . $expected . '"]')) {
            $children = $xml->xpath($body . '/*');
            $actual = $children ? self::safeText($children[0]->getName()) : 'ausente';
            throw new self($http . ' motivo=resposta_inesperada recebido=' . $actual . ' esperado=' . $expected);
        }
    }

    private static function safeText(string $value): string
    {
        $value = trim(preg_replace('/[\p{C}\s]+/u', ' ', $value) ?? '');
        if ($value === '') {
            return 'ausente';
        }
        if (preg_match('/senha|password|secret|token|authorization|bearer|credential|credencial|https?:|[<>@=]|\b[A-Za-z0-9+\/_-]{30,}\b/i', $value)) {
            return '[omitido]';
        }
        return mb_substr(preg_replace('/\d{5,}/', '[numero]', $value), 0, 200);
    }
}
