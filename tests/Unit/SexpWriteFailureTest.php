<?php

use App\Exceptions\SexpWriteFailure;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\TestCase;

class SexpWriteFailureTest extends TestCase
{
    /** @dataProvider responses */
    public function testDiagnostic(int $status, string $body, string $expected): void
    {
        $this->expectException(SexpWriteFailure::class);
        $this->expectExceptionMessage($expected);
        $xml = '<s:Envelope xmlns:s="urn:test"><s:Body>' . $body . '</s:Body></s:Envelope>';
        SexpWriteFailure::check(new Response(new PsrResponse($status, [], $xml)), 'informarNFResponse');
    }

    public static function responses(): array
    {
        $fault = '<s:Fault><faultcode>s:Client</faultcode><faultstring>Nota já vinculada</faultstring></s:Fault>';
        return [
            [500, $fault, 'http=500 motivo=soap_fault faultcode=s:Client faultstring=Nota já vinculada'],
            [200, $fault, 'http=200 motivo=soap_fault'],
            [500, str_replace('Nota já vinculada', 'token: privado', $fault), 'faultstring=[omitido]'],
            [200, '<outra/>', 'recebido=outra esperado=informarNFResponse'],
            [503, '', 'http=503 motivo=erro_http'],
            [502, '<', 'http=502 motivo=xml_ausente_ou_invalido'],
        ];
    }

    public function testConnectionAndSuccessfulReply(): void
    {
        $this->assertSame('http=indisponivel motivo=falha_de_conexao', SexpWriteFailure::describe(new ConnectionException('private')));
        $xml = '<Envelope><Body><informarNFResponse/></Body></Envelope>';
        SexpWriteFailure::check(new Response(new PsrResponse(200, [], $xml)), 'informarNFResponse');
    }
}
