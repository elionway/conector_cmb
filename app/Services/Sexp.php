<?php

namespace App\Services;

use App\Models\Faturado;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
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

        $order_prepared = $this->formatOrderDataToSexp($order);

        $params = $this->parseCreateOrderParams($order_prepared);
        self::logSendSexp('CREATE PACOTE: '.$order['order_id']);

        $this->execute($params);

    }

    public function orderHasTrackingNumber(int $order_id): bool
    {
        $order = $this->getOrder($order_id);
        
       # dd($order);
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
       
        $response = $this->renderOrderFromRequest($order);
        //dd($response);
        return $response;
    }

    public function updateInvoicedOrder(array $order, Faturado $invoice)
    {
        $order_id = $order['order_id'];

        $params = $this->parseUpdateOrderParams($order, $invoice);        
        
        try{
            $this->execute($params);
            self::logSendSexp('UPDATE: '.$order_id);
        }catch (\Throwable $th) {
            self::logSendSexp('FALHA NO UPDATE: '.$order_id);
        }        
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
        //return [];
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
        //return  [];
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

    private function log(string $url, Response $response, ?string $params = '',  bool $isCreatingOrder = false)
    {
        $logData = [
            'url' => $url,
            'body' => $response->body(),
            'params' => $params,
        ];

        $stringfiedData = json_encode($logData);

        $key = Carbon::now()->format('YmdHis') . '-' . md5($stringfiedData . time());

        // $slugedUrl = Str::slug($url);

        // $path = "api-calls/sexp/{$key}.txt";

        // if ($isCreatingOrder) {
        //     $path = "api-calls/sexp/creating/{$key}.txt";
        // }

        // Storage::put($path, $stringfiedData);
    }

    private function createXmlFromArray(array $params): string
    {
        $xml = Array2XML::createXML('RootNode', $params)->saveXml();

        return str_replace(['<RootNode>', '</RootNode>'], ['', ''], $xml);
    }

    private function address(): array
    {
        $address = Sexp::addressSettings();

        return [
            'bairro' => $address['neighborhood'],
            'cep' => $address['postcode'],
            'cidade' => $address['city'],
            'complemento' => '',
            'numero' => $address['number'],
            'rua' => $address['address'],
            'uf' => $address['state_code'],
        ];
    }

    private function package(array $order): array
    {
        $package['height'] = 0;

        $package['length'] = 0;

        $package['width'] = 0;

        $package['weight'] = 0;

        foreach ($order['products'] as $product) {
            $package['weight'] += $product['weight'] * $product['quantity'];

            $package['height'] += $product['height'] * $product['quantity'];

            $package['length'] += $product['length'] * $product['quantity'];

            $package['width'] += $product['width'] * $product['quantity'];
        }

        return $package;
    }

    private function formatOrderDataToSexp(array $order): array
    {
        $package = $this->package($order);

        return [
            'chaveUnica'      => 'ECMB' . str_pad($order['order_id'], 9, '0', STR_PAD_LEFT),
            'codigoProduto'   => '803479',
            'enderecoDevolucao' => $this->address(),
            'enderecoPrincipal' => [
                'bairro'      => Erp::prepareDistrict($order),                
                'cep'         => Erp::preparePostcode($order),
                'cidade'      => Erp::prepareCity($order),
                'complemento' => Erp::prepareAddressComplement($order),
                'numero'      => (string)Erp::prepareAddressNumber($order),
                'rua'         => Erp::prepareAddressStreet($order),
                'uf'          => Erp::prepareState($order),
            ],
            'idServico'       => 12,
            'nomeCliente'     => Erp::prepareFullname($order),
            'codigoCliente'   => 1,
            'quantidade'      => $this->totalProductQuantities($order['products']),
            'tipoEmbalagem'   => 'CAIXA/PACOTE',
            'valor'           => $order['total'],
            'altura'          => number_format((float)$package['height'], 3, '.', ''),
            'comprimento'     => number_format((float)$package['length'], 3, '.', ''),
            'largura'         => number_format((float)$package['width'] , 3, '.', ''),
            'peso'            => number_format((float)$package['weight'], 3, '.', ''),
        ];
    }


    private function totalProductQuantities(array $products): int
    {
        $quantity = 0;

        foreach ($products as $product) {
            $quantity += $product['quantity'];
        }

        return $quantity;
    }

    public static function logSendSexp(string $log)
    {
        var_dump($log);

        $log = date("Y-m-d h:i:sa")." - ".$log ;
        $log = $log . PHP_EOL;
        File::append(
            storage_path('/logs/send_sexp.log'), $log
       );
    }

}