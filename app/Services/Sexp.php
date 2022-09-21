<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Storage;
use LaLit\Array2XML;
use LaLit\XML2Array;
use \Exception;
use Carbon\Carbon;


class Sexp {
    private ?array $address_settings = [];

    public function __construct()
    {
        $this->address_settings = config('connector.main_address');
    }

    public function createOrder(array $order)
    {
        $params = $this->parseCreateOrderParams($order);

        $this->execute($params);
    }

    public function orderHasTrackingNumber(int $order_id): bool
    {
        return !!$this->getOrderTrackingNumber($order_id);
    }

    public function getOrderTrackingNumber(int $order_id): ?string
    {
        $params = [
            'chaveUnica' => 'ECMB' . str_pad($order_id, 9, '0', STR_PAD_LEFT),
            'codigoProduto' => '803479',
        ];

        $params = $this->parseSearchOrderParams($params);

        $order = $this->execute($params);

        return $this->renderTrackingNumberFromRequest($order);
    }

    private function renderTrackingNumberFromRequest(array $response): ?string
    {
        $package = $response['soap:Envelope']['soap:Body']['ns2:consultarRemessaResponse']['informacoesRemessa'];

        return $package['numeroPostagem'] ?? null;
    }

    public function addressSettings(): array
    {
        return $this->address_settings;
    }

    private function parseSearchOrderParams(array $params): array
    {
        $params = [
            'soapenv:Envelope' => [
                '@attributes' => [
                    'xmlns:soapenv' => 'http://schemas.xmlsoap.org/soap/envelope/',
                    'xmlns:ws' => 'http://ws.sexpwebservice.cmb.gov.br/',
                ],
                'soapenv:Body' => [
                    'ws:consultarRemessa' => $params,
                ],
            ],
        ];

        return $params;
    }

    private function parseCreateOrderParams(array $params): array
    {
        $params = [
            'soapenv:Envelope' => [
                '@attributes' => [
                    'xmlns:soapenv' => 'http://schemas.xmlsoap.org/soap/envelope/',
                    'xmlns:ws' => 'http://ws.sexpwebservice.cmb.gov.br/',
                ],
                'soapenv:Body' => [
                    'ws:incluirRemessa' => [
                        'remessa' => $params,
                    ],
                ],
            ],
        ];

        return $params;
    }

    private function execute(array $params)
    {
        $params = $this->createXmlFromArray($params);

        $url = 'http://192.168.250.75/sexp-ws/SexpWS?wsdl';

        $request = Http::withHeaders([
            'Content-Type' => 'text/xml;charset=UTF-8', 
            'Accept-Encoding' => 'gzip, deflate'
        ])
        ->withBody($params, 'text/xml')
        ->post($url);

        $this->log($url, $request, $params);

        throw_if($request->failed(), new Exception($request->body()));

        $response = $request->body();

        return XML2Array::createArray($response);
    }

    private function log(string $url, Response $response, ?string $params = '')
    {
        $log_data = [
            'url' => $url,
            'body' => $response->body(),
            'params' => $params,
        ];

        $stringfied_data = json_encode($log_data);

        $key = Carbon::now()->format('YmdHis') . '-' . md5($stringfied_data . time());

        Storage::put("api-calls/sexp/{$key}.txt", $stringfied_data);
    }

    private function createXmlFromArray(array $params): string
    {
        $xml = Array2XML::createXML('RootNode', $params)->saveXml();

        return str_replace(['<RootNode>', '</RootNode>'], ['', ''], $xml);
    }
}