<?php

namespace App\Services;

use App\Exceptions\SexpWriteFailure;
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

    private function createOrder(array $order)
    {

        $order_prepared = $this->formatOrderDataToSexp($order);

        $params = $this->parseCreateOrderParams($order_prepared);
        self::logSendSexp('CREATE PACOTE: '.$order['order_id']);

        $this->execute($params);

    }

    public function orderHasTrackingNumber(int $order_id): bool
    {

        
        try {
            $order = $this->getOrder($order_id);
            
            if (!app(ConnectorAudit::class)->enabled()) {
                $this->logDebug("order: ".print_r($order, true));
            }

            
            if (!empty($order['status']['codigo'])){
               
                if($order['status']['codigo'] == '3' || $order['status']['codigo'] == '5'){
                  //  dd($order['status']);
                  //var_dump("IF order['status']['codigo']".$order['status']['codigo']);
                    return  true;
                } 
              //  dd($order);
             // var_dump("ELSE order['status']['codigo']".$order['status']['codigo']);
                return false;
                
            }elseif (!empty($order['error'])){
            //    dd($order['error']);
                Opencart::orderHistory($order_id, [
                    'order_status_id' => Opencart::updateStatuses('with_error_item_data'),
                    'comment' => $order,
                    'notify' => false,
                ]);
            }      

        } catch (\Throwable $th) {
                \App\Services\ConnectorAlertReporter::reportSafely($th, 'sexp');
            return false;
        }
         
    }

    private function orderQuery(int $order_id): array
    {
        return $this->parseSearchOrderParams([
            'chaveUnica' => 'ECMB' . str_pad($order_id, 9, '0', STR_PAD_LEFT),
            'codigoProduto' => '803479',
        ]);
    }

    public function inspectOrder(int $order_id): Response
    {
        $params = $this->orderQuery($order_id);
        return app(ConnectorAudit::class)->operation(
            'sexp.query',
            fn () => $this->sendSoap($params),
            ['order_id' => $order_id]
        );
    }

    public function getOrder(int $order_id): array
    {
        $order = $this->execute($this->orderQuery($order_id));
       
        $response = $this->renderOrderFromRequest($order);
        app(ConnectorAudit::class)->record('sexp.status_received', [
            'order_id'=>$order_id, 'status_code'=>$response['status']['codigo'] ?? null,
            'error'=>!empty($response['error']), 'tracking_present'=>!empty($response['numeroPostagem']),
        ]);
        //dd($response);
        return $response;
    }

    public function processInvoicedOrder(array $order, Faturado $invoice): void
    {
        $invoiceData = $this->invoiceData($order, $invoice);

        ShippingCarrier::fromOrder($order);
        $this->ensureOrderExists($order);

        $this->sendInvoicedOrder($order, $invoiceData);
    }

    public function updateInvoicedOrder(array $order, Faturado $invoice): void
    {
        $this->sendInvoicedOrder($order, $this->invoiceData($order, $invoice));
    }

    private function ensureOrderExists(array $order): void
    {
        $response = $this->getOrder((int)$order['order_id']);

        if (empty($response['error'])) {
            return;
        }

        if (!$this->isMissingOrderResponse($response)) {
            throw new \RuntimeException('Falha ao confirmar a existência da remessa no SEXP.');
        }

        $this->createOrder($order);
    }

    private function sendInvoicedOrder(array $order, array $invoiceData): void
    {
        $order_id = $order['order_id'];

        $params = $this->parseUpdateOrderParams($order, $invoiceData);
        
        try{
            $this->execute($params);
            self::logSendSexp('UPDATE: '.$order_id);
        }catch (\Throwable $th) {
            self::logSendSexp('FALHA NO UPDATE: ' . $order_id . ' operacao=informarNF ' . SexpWriteFailure::describe($th));
            \App\Services\ConnectorAlertReporter::reportSafely($th, 'sexp');
            throw $th;
        }
    }

    private function parseUpdateOrderParams(array $order, array $invoiceData): array
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
                        'notaFiscal' => $invoiceData,
                    ],
                ],
            ],
        ];
    }

    private function invoiceData(array $order, Faturado $invoice): array
    {
        $keyField = (string)config('connector.invoice_fields.key', 'CHAVE_NFE');
        $protocolField = (string)config('connector.invoice_fields.protocol', 'PROTOCOLO_NFE');
        $key = trim((string)($invoice->getAttribute($keyField) ?? $invoice->getAttribute('CHAVENFE')));
        $protocol = trim((string)($invoice->getAttribute($protocolField) ?? $invoice->getAttribute('PROTOCOLO')));
        $issued = trim((string)$invoice['EMISSAO']);
        $date = \DateTimeImmutable::createFromFormat('!Ymd', $issued);
        $number = trim((string)$invoice['NOTA']);
        $series = trim((string)$invoice['SERIE']);
        if (!preg_match('/^[0-9]{44}$/D', $key) || !ctype_digit($protocol)
            || !$date || $date->format('Ymd') !== $issued || !ctype_digit($number)
            || (int)$number === 0 || !ctype_digit($series) || !is_numeric($order['total'] ?? null)) {
            throw new \UnexpectedValueException('NF incompleta ou inválida no ERP.');
        }

        return [
            'chaveNfe' => $key,
            'data' => $date->format('Y-m-d\TH:i:s'),
            'numero' => (int)$number,
            'protocoloNfe' => $protocol,
            'serie' => (int)$series,
            'valor' => (float)$order['total'],
        ];
    }

    public function isMissingOrderResponse(array $response): bool
    {
        $text = json_encode($response, JSON_UNESCAPED_UNICODE);

        if (!is_string($text)) {
            return false;
        }

        return stripos($text, 'Remessa não encontrada') !== false
            || stripos($text, 'Remessa nÃ£o encontrada') !== false
            || stripos($text, 'Remessa nĂ£o encontrada') !== false;
    }

    private function renderOrderFromRequest(array $response): array
    {
       // dd($response);
        
        if(!empty($response['soap:Envelope']['soap:Body']['ns2:consultarRemessaResponse']['informacoesRemessa'])){
            return $response['soap:Envelope']['soap:Body']['ns2:consultarRemessaResponse']['informacoesRemessa'];
        }

        return ['error' => $response];
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
        $body = $params['soapenv:Envelope']['soapenv:Body'] ?? [];
        $action = array_key_first($body);
        $steps = ['ws:consultarRemessa'=>'sexp.query', 'ws:incluirRemessa'=>'sexp.create', 'ws:informarNF'=>'sexp.invoice'];
        $step = $steps[$action] ?? 'sexp.unknown';
        $key = $body[$action]['chaveUnica'] ?? $body[$action]['remessa']['chaveUnica'] ?? '';
        $fields = preg_match('/^ECMB([0-9]{9})$/D', $key, $match) ? ['order_id'=>(int)$match[1]] : [];
        return app(ConnectorAudit::class)->operation($step, fn () => $this->executeTransport($params), $fields);
    }

    private function executeTransport(array $params)
    {
        $response = $this->sendSoap($params);
        $action = array_key_first($params['soapenv:Envelope']['soapenv:Body']);
        if ($action !== 'ws:consultarRemessa') {
            SexpWriteFailure::check($response, substr($action, 3) . 'Response');
        }
        return XML2Array::createArray($response->body());
    }

    private function sendSoap(array $params): Response
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


        app(ConnectorAudit::class)->record('sexp.http_received', ['http_status'=>$request->status()]);
        return $request;
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
            'idServico'       => ShippingCarrier::sexpServiceId($order),
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
        //var_dump($log);

        $log = date("Y-m-d h:i:sa")." - ".$log ;
        $log = $log . PHP_EOL;
        File::append(
            storage_path('/logs/send_sexp.log'), $log
       );
    }
    public static function logDebug(string $log)
    {

        $log = date("Y-m-d h:i:sa")." - ".$log ;
        $log = $log . PHP_EOL;
        File::append(
            storage_path('/logs/logDebug.log'), $log
       );
    }

}
