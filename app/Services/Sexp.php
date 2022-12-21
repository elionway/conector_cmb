<?php

namespace App\Services;

use App\Models\Faturado;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Storage;
use LaLit\Array2XML;
use LaLit\XML2Array;
use \Exception;
use Carbon\Carbon;
use Illuminate\Support\Str;


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
        $order = $this->getOrder($order_id);

        return $order['status']['codigo'] == '3';
    }

    public function getOrder(int $order_id): array
    {
        $params = [
            'chaveUnica' => 'ECMB' . str_pad($order_id, 9, '0', STR_PAD_LEFT),
            'codigoProduto' => '803479',
        ];

        $params = $this->parseSearchOrderParams($params);

        $order = $this->execute($params);

        return $this->renderOrderFromRequest($order);
    }

    public function updateInvoicedOrder(array $order, Faturado $invoice)
    {
        $order_id = $order['order_id'];

        $params = $this->parseUpdateOrderParams($order, $invoice);

        $this->execute($params);
    }

    private function parseUpdateOrderParams(array $order, Faturado $invoice): array
    {
        $order_id = $order['order_id'];

        return [
            'soapenv:Envelope' => [
                '@attributes' => [
                    'xmlns:soapenv' => 'http://schemas.xmlsoap.org/soap/envelope/',
                    'xmlns:ws' => 'http://ws.sexpwebservice.cmb.gov.br/',
                ],
                'soapenv:Body' => [
                    'ws:informarNF' => [
                        'chaveUnica' => 'ECMB' . str_pad($order_id, 9, '0', STR_PAD_LEFT),
                        'codigoProduto' => '803479',
                        'notaFiscal' => [
                            'data' => Carbon::createFromFormat('Ymd', $invoice['EMISSAO'])->format('Y-m-d'),
                            'numero' => $invoice['NOTA'],
                            'serie' => trim($invoice['SERIE']),
                            'valor' => $order['total'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function renderOrderFromRequest(array $response): array
    {
        return $response['soap:Envelope']['soap:Body']['ns2:consultarRemessaResponse']['informacoesRemessa'];
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

    private function isCreatingOrder(string $xml): bool
    {
        return Str::contains($xml, 'incluirRemessa');
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

        $this->log($url, $request, $params, $this->isCreatingOrder($params));

        throw_if($request->failed(), new Exception($request->body()));

        $response = $request->body();

        return XML2Array::createArray($response);
    }

    private function log(string $url, Response $response, ?string $params = '',  bool $isCreatingOrder)
    {
        $logData = [
            'url' => $url,
            'body' => $response->body(),
            'params' => $params,
        ];

        $stringfiedData = json_encode($logData);

        $key = Carbon::now()->format('YmdHis') . '-' . md5($stringfiedData . time());

        $slugedUrl = Str::slug($url);

        $path = "api-calls/sexp/{$key}.txt";

        if ($isCreatingOrder) {
            $path = "api-calls/sexp/creating/{$key}.txt";
        }

        Storage::put($path, $stringfiedData);
    }

    private function createXmlFromArray(array $params): string
    {
        $xml = Array2XML::createXML('RootNode', $params)->saveXml();

        return str_replace(['<RootNode>', '</RootNode>'], ['', ''], $xml);
    }
}