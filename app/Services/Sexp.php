<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use LaLit\Array2XML;
use LaLit\XML2Array;

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
        ];

        $params = $this->parseSearchOrderParams($params);

        $order = $this->execute($params);

        return $this->renderTrackingNumberFromRequest($order);
    }

    private function renderTrackingNumberFromRequest(array $response): ?string
    {
        return 'HSFUIODHSOF';
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

        $request = Http::post($url, $params);

        if ($request->failed()) {
            return;    
        }

        $response = $request->body();

        return XML2Array::createArray($response);
    }

    private function createXmlFromArray(array $params): string
    {
        $xml = Array2XML::createXML('RootNode', $params)->saveXml();

        return str_replace(['<RootNode>', '</RootNode>'], ['', ''], $xml);
    }
}