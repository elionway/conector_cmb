<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Titulo;
use App\Models\Faturado;
use App\Models\Valid;
use App\Support\Opencart;
use App\Support\Sexp;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;


class Erp {
    private ?string $application_code = '';

    private ?string $ibge_country = '';

    private ?array $payment_methods = [];

    private ?array $payment_codes = [];

    private ?bool $haslog = false;

    public function __construct()
    {
        $this->application_code = config('connector.application_code');
        
        $this->ibge_country = config('connector.ibge_country');

        $this->payment_methods =  config('connector.payment_methods');

        $this->payment_codes = config('connector.payment_codes');

        $this->haslog = true;

    }

    public function createOrder(array $order)
    {

        $order = $this->formatOrderDataToErp($order);

         DB::transaction(function () use ($order) {
            
             $this->createCustomer($order['customer']);             

             $this->createOrderProducts($order['orders']);
             $this->createBills($order['billing']);

            //ajustar pedidos com erro de 1 item
            #$this->createOrUpdateItemsForOnlyErrors($order['orders']);
            #$this->storeBillsForOnlyErrors($order['billing']);

            //$this->deleteAndCreateOrderProducts($order['orders']);

         });
    }

    public function orderHasInvoice(int $orderId): bool
    {
        return !!$this->find($orderId);
    }

    public function getOrder(int $orderId)
    {

        $items = Pedido
                ::where('PV_NUMPED', '000'.$orderId)->get()
                ;     

        foreach($items as $item){
            echo "PV_NUMPED: ".$item['PV_NUMPED']. ", PV_CODPR: ".$item['PV_CODPR']. ", PV_QTDPED: ".$item['PV_QTDPED']."
            ";
        }
       
       
    }

    public function find(int $orderId): ?Faturado
    {
        $return = Faturado::where('PEDIDO', str_pad($orderId, 9, "0", STR_PAD_LEFT))->first(); 
        //var_dump( "FATURADO: ". $orderId." ");
        //var_dump($return);
        return $return;
    }

    public function applicationCode(): string
    {
        return $this->application_code;
    }

    public function getIbgeCountry(): string
    {
        return str_pad($this->ibge_country, 5, "0", STR_PAD_LEFT);
    }

    private function createCustomer(array $data)
    {
        //$this->logSendErp('DELETE CLIENTE: '.$data['CL_CPFCNPJ']);

        Cliente::
        where('CL_APLIC', $data['CL_APLIC'])
        ->where('CL_CPFCNPJ', $data['CL_CPFCNPJ'])
        ->delete();

        $this->logSendErp('CREATE CLIENTE: '.$data['CL_CPFCNPJ']);
        Cliente::create($data);
    }

    
    private function logItems($log)
    {
        $log = date("Y-m-d h:i:sa")." - ".$log ;
        $log = $log . PHP_EOL;
        File::append(
            storage_path('/logs/error_items.log'), $log
       );
    }

    private function createOrUpdateItemsForOnlyErrors(array $items)
    {
        $ultimo_item = '';
        $pedido =  '';
        $item_pri = $items[0]['PV_CODPR'];
        foreach ($items as $key => $item) {           
            $item_ult = $item['PV_CODPR'];
            $pedido =  substr($item['PV_NUMPED'], -6);
        }

        $item_no_erp = '';

        if(isset($order_problem[$pedido])){
           // var_dump('achou pedido');

            $item_erp = array_key_first($order_problem[$pedido]);
            $item_erp_qt = $order_problem[$pedido];
        }
        
        var_dump('Item pri: '. $item_pri);
        var_dump('Item ult: '. $item_ult);
        
        foreach ($items as $item) {
            var_dump('Item: '. $item['PV_CODPR']);
            if($item['PV_CODPR'] == $item_pri){
                $item_para_editar = Pedido
                ::where('PV_NUMPED', $item['PV_NUMPED'])
                ->where('PV_CODPR', $item_ult);
                
                if($item_para_editar){
                   
                   // $objeto = $item_para_editar['PV_CODPR'];
                   $objeto = $item_para_editar->value('PV_OBJETO');
                   $item['PV_OBJETO'] = $objeto;
                   #$item_para_editar->update($item);
                   var_dump('vai editar o item. erp: '.$item['PV_CODPR']." , dmz: ".$item_ult);
                }
            }
        }

        foreach ($items as $item) {                     

            if( $item['PV_CODPR'] == $item_pri ){
                var_dump('DELETE PULOU PRI item. erp: '.$item['PV_CODPR']);
                continue;
            }

            $pedido = Pedido
                ::where('PV_NUMPED', $item['PV_NUMPED'])
                ->where('PV_CODPR', $item['PV_CODPR'])
                //->whereNot('PV_CODPR', $item_pri)
                //->where('PV_QTDPED', $item['PV_QTDPED'])
                //->first();
                ->delete();
                var_dump('DELETE item. erp: '.$item['PV_CODPR']);
        }

        foreach ($items as $item) {                     
            
            if( $item['PV_CODPR'] == $item_pri){
                var_dump('PULOU PRI item. erp: '.$item['PV_CODPR']);
                continue;
            }
            
            $pedido = Pedido
                ::where('PV_NUMPED', $item['PV_NUMPED'])
                ->where('PV_CODPR', $item['PV_CODPR'])
                ->whereNot('PV_CODPR', $item_pri)
                //->where('PV_QTDPED', $item['PV_QTDPED'])
                ->first();
                //->delete();
                var_dump("item prod:: ".$item['PV_CODPR']);
                #var_dump($pedido);
                if($pedido){
                    var_dump('PULOU item. erp: '.$item['PV_CODPR']);
                    continue;
                }

                //var_dump('PV_NUMPED: '.$pedido->value('PV_NUMPED').', PV_CODPR: '.$pedido->value('PV_CODPR'));
                $item['PV_OBJETO'] = $objeto;
               // $item['PV_FRETE'] = 0;                
               // $item['PV_AR'] =  $item['PV_AR'].'. Pedido complementar para o pedido '.$item['PV_NUMPED'].'. Procedimento realizado pois houve um erro na sincronia dos itens dos pedidos vindos do ecommerce';
                //$item['PV_NUMPED'] =  'E'.$item['PV_NUMPED'];

                Pedido::create($item);
                var_dump('vai CRIAR o item. erp: '.$item['PV_CODPR']);
        }
    }

    private function storeBillsForOnlyErrors(array $bills)
    {
        foreach ($bills as $bill) {
            $titulo = Titulo::where('TI_NUM', $bill['TI_NUM'])
            ->where('TI_PREFIXO', $bill['TI_PREFIXO'])
            //->where('TI_STATUS', 'M')
            ->delete();
            var_dump('DELETE TITULO. erp TI_NUM: '.$bill['TI_NUM']);
            //->first();

            // if ($titulo) {
            //     Titulo::where('TI_NUM', $bill['TI_NUM'])->where('TI_PREFIXO', $bill['TI_PREFIXO'])->update($bill);
            //     continue;
            // }
            var_dump('CREATE TITULO. erp TI_NUM: '.$bill['TI_NUM']);
            Titulo::create($bill);
        }
    }



    private function createOrderProducts(array $order_products)
    {     
        
        foreach ($order_products as $order_product) {                     
            
            $pedido = Pedido
                ::where('PV_NUMPED', $order_product['PV_NUMPED'])
                ->where('PV_CODPR', $order_product['PV_CODPR'])
                ->where('PV_QTDPED', $order_product['PV_QTDPED'])
                ->where('PV_CPFCNPJ', $order_product['PV_CPFCNPJ'])
                ->first();               

            $text = "PEDIDO PRODUTO: PED: ". $order_product['PV_NUMPED']. ", PROD: ". $order_product['PV_CODPR'] .", QNT: ".$order_product['PV_QTDPED'];

            if($pedido){
                $this->logSendErp('IGNORE '.$text);
                continue;
            }

            $this->logSendErp('CREATE '.$text);
            Pedido::create($order_product);

        }
    }

    private function deleteAndCreateOrderProducts(array $order_products)
    {     
        
        foreach ($order_products as $order_product) {                     
            
            $pedido = Pedido
                ::where('PV_NUMPED', $order_product['PV_NUMPED'])
                ->where('PV_CODPR', $order_product['PV_CODPR'])
                ->delete();               

            $text = "PEDIDO PRODUTO Deletado e Recriado: PED: ". $order_product['PV_NUMPED']. ", PROD: ". $order_product['PV_CODPR'] .", QNT: ".$order_product['PV_QTDPED'];

            $this->logSendErp('CREATE '.$text);
            Pedido::create($order_product);

        }
    }    

    private function createBills(array $bills)
    {
        foreach ($bills as $bill) {
            $titulo = Titulo
                ::where('TI_NUM', $bill['TI_NUM'])
                ->where('TI_PREFIXO', $bill['TI_PREFIXO'])
                ->where('TI_CPFCNPJ', $bill['TI_CPFCNPJ'])
                ->first();

            $text = "TITULO: TI_NUM: ".$bill['TI_NUM'].", TI_PREFIXO: ".$bill['TI_PREFIXO'];

            if ($titulo) {
                $this->logSendErp('IGNORE '.$text);
                continue;
            }
            
            $this->logSendErp('CREATE '.$text);
            Titulo::create($bill);
        }
    }

    
    private function tesCode(array $order, array $product): string
    {   
        $productTypes = [
            '4' => '654',
            '5' => '890',
        ];

        if (isset($productTypes[$product['cest']])) {
            return $productTypes[$product['cest']];
        }

        if ($this->isOrderInRj($order) || $this->hasStateRegistration($order['custom_fields'])) {
            if ($this->isMedal($product['cest'])) {
                return '597';
            }

            if ($this->isCoin($product['cest'])) {
                return '504';
            }
        }

        if ($this->isMedal($product['cest'])) {
            return '598';
        }

        if ($this->isCoin($product['cest'])) {
            return '50A';
        }
    }

    private function isMedal(string $productType): bool 
    {   
        return $productType == '1';
    }

    private function isCoin(string $productType): bool
    {
        return in_array($productType, ['2', '3']);
    }

    private function hasStateRegistration(array $custom_fields): bool
    {

        return !empty( Opencart::getCustomField($custom_fields, Opencart::stateRegistration()) );
    }

    private function isOrderInRj(array $order): bool
    {
        return $order['shipping']['zone_code'] == 'RJ';
    }

    private function messageAr(array $order, array $product): string
    {
        $message = "PAM {$order['payment']['transaction']['payment_id']} PED {$this->orderId($order['order_id'])}";

        if ($order['payment']['transaction']['tid']) {
            $message .= " TID {$order['payment']['transaction']['tid']}";
        }

        if ($this->hasBc($order['products'])) {
            return "{$message} Contrato Nº 50553/2016  entre Casa da Moeda do Brasil e Banco Central. Operação com Imunidade tributária estabelecida pelo art.150, VI, a, CRFB/88 e isenta de ICMS conforme Convênio ICMS 01/91.";
        }

        if ($this->tesCode($order, $product) === '504') {
            return "{$message} ICMS - ISENTO COM BASE NO CONVENIO 01/91 ";
        }

        return "{$message} ";
    }

    private function orderId($order_id): string
    {
        return str_pad($order_id, 9, '0', STR_PAD_LEFT);
    }

    private function hasBc(array $products): bool
    {
        foreach ($products as $product) {
            if ($this->isBc($product)) {
                return true;
            }
        }

        return false;
    }

    private function isBc(array $product): bool
    {
        return $product['cest'] == 3;
    }

    private function parsePaymentMethodData(array $order): array
    {
        $paymentMethods =  $this->payment_methods;
        $paymentCodes = $this->payment_codes;

        $orderPaymentMethod = $order['payment']['transaction']['payment_code'] ?? '';

        $paymentCode = $paymentCodes[$orderPaymentMethod];

        if (is_array($paymentCodes[$orderPaymentMethod])) {
            $order_installment = $order['payment']['transaction']['installments']??1;

            $installment = $order_installment;
            if (!in_array($order_installment, [1, 2, 3, 4, 5, 6])) {
                $installment = 1;
            }

            $paymentCode = $paymentCodes[$orderPaymentMethod][$installment];
        }  

        return [
            'paymentMethod' => $paymentMethods[$orderPaymentMethod] ?? 1,
            'paymentCode' => $paymentCode
        ];
    }

    public static function getMainPhone(array $order): string
    {
        $telephone = valid::cleanNumber( Opencart::getCustomField($order['custom_fields'], Opencart::cellphone()))
        ?? valid::cleanNumber( $order['telephone'] );

        $ddd = substr($telephone, 0, 2);

        $telefone = substr($telephone, 2, 99);;

        if(in_array(substr($telefone, 0, 1), [9,8,7]) ){
            $telefone = str_pad($telefone, 9, '9', STR_PAD_LEFT);
        }

        $telephone = $ddd.$telefone;

        // if(empty($telephone)){
        //     $telephone = '21999999999';
        // }

         return $telephone;

    }

    public static function prepareDdd(array $order): string
    {

        $telephone = self::getMainPhone($order);

        return substr($telephone, 0, 2);
    }

    public static function prepareTelephone(array $order): string
    {
        $telephone = self::getMainPhone($order);

        return substr($telephone, 2);
    }

    public static function prepareAddressNumber(array $order): string
    {
        $address_number = valid::cleanNumber( Opencart::getCustomField($order['shipping']['custom_field'], Opencart::addressNumber()));
        
        if(empty( $address_number)){
            (string)$address_number = (string)"0";
        }
        return (string)$address_number;
    }

    public static function prepareAddressComplement(array $order): string
    {
        return   valid::cleanNumber( Opencart::getCustomField($order['shipping']['custom_field'], Opencart::addressComplement()));
    }

    public static function prepareAddressStreet(array $order): string
    {
        $street = strtolower(valid::cleanString($order['shipping']['address_1']));

        $replace = [
            'avenida '      => 'av ',
            //'rua '          => ' ',
            'praca '        => 'pc ',
            'alameda '      => 'al ',
            'santa '        => 'sta ',
            'santo '        => 'sto ',
            'quadra '       => 'qd ',
            'lote '         => 'lt ',
            'republica'     => 'rep',
            'jardim'        => 'jd',
            'prefeitura'    => 'pref',
            'municipal'     => 'mun',
            'loteamento'    => 'ltmto',
            'universitario' => 'univ',
            'restaurante'   => 'rest',
            'maracanabom'   => 'mar',
            'presidente'    => 'pr',
            'governador'    => 'gov',
            'desembargador' => 'des',
            'monsenhor'     => 'mon',
            'residencial'   => 'res',
            'vila '         => 'v ',
            //'centro '       => ' ',
            'condominio'    => 'cnd',
            'casa '         => 'cs ',
            'bloco '        => 'bl ',
            'apto '         => 'ap ',
            'apartamento'   => 'ap',
            'aptmt'         => 'ap',
        ];

        $street = str_replace(array_keys($replace), array_values($replace), $street);

        return   valid::cleanString($street);
    }

    public static function prepareAddress(array $order): string
    {
        $address_number = self::prepareAddressNumber($order);        
       
        $address_complement = self::prepareAddressComplement($order);       
         
        $street = self::prepareAddressStreet($order);

        if(strlen($address_complement ) > 5){
            $street = str_replace($address_complement, '', $street);
        }

        if(strlen($address_number ) > 2){
            $street = str_replace($address_number, '', $street);
        }
        
        
        if(empty($address_number)){
            $address = "{$street}, 0 {$address_complement}";
        }else{
            $address = "{$street}, {$address_number} {$address_complement}";
        }

        $street = str_replace('  ', ' ', $street);


        return trim($address);

    }
   
    public static function prepareFullname(array $order): string
    {

        $firstname = $order['firstname']??'';
        $lastname = $order['lastname']??'';

        if(!empty(self::customerIsPf($order))){
            $fullname = $firstname. ' ' . $lastname;
        }else{
            $fullname = $firstname;
        }
       
        return substr(valid::cleanString($fullname), 0, 59);
    }

    public static function prepareDistrict(array $order): string
    {
        return  substr(valid::cleanString($order['shipping']['address_2']), 0, 29);
    }

    public static function preparePostcode(array $order): string
    {        
       return valid::cleanNumber($order['shipping']['postcode']??'');
    }
    
    public static function prepareCity(array $order): string
    {
        return substr(valid::cleanString($order['shipping']['city']??''), 0, 32);
    }

    public static function prepareState(array $order): string
    {
        return valid::cleanString($order['shipping']['zone_code']??'');
    }

    public static function prepareCountry(array $order): string
    {
        return valid::cleanString($order['shipping']['country']??'');
    }

    public static function prepareStateRegistration(array $order): string
    {
        return !empty(self::customerIsPj($order)) ? valid::cleanNumber(Opencart::getCustomField($order['custom_fields'], Opencart::stateRegistration())) : '';
    }

    public static function prepareCustomerType(array $order): string
    {
        return !empty(self::customerIsPj($order)) ? 'J' : 'F';
    }

    public static function customerIsPf(array $order): bool
    {
        return $order['customer_group_id'] == 2 ? false : true;
    }

    public static function customerIsPj(array $order): bool
    {
        return !self::customerIsPf($order);
    }


    private function getCustomerData(array $order): array
    {       

        return [
            'CL_APLIC'   => 'ECMB',
            'CL_STATUS'  => 'M',
            'CL_CPFCNPJ' => self::prepareDocument($order),
            'CL_END'     => self::prepareAddress($order),
            'CL_BAIRRO'  => self::prepareDistrict($order),
            'CL_CEP'     => self::preparePostcode($order),
            'CL_DDD'     => self::prepareDdd($order),
            'CL_TEL'     => self::prepareTelephone($order),
            'CL_EMAIL'   => $order['email'], // required
            'CL_DELET'   => '',
            'CL_CODMUN'  => valid::getIbgeCodigoMunicipioByOrderInfo($order), // required
            'CL_CODPAIS' => Erp::getIbgeCountry(), // required
            'CL_INCCULT'  => '2', // required
            'CL_NOME'    => self::prepareFullname($order),
            'CL_SIMPLES' => '2',
            'CL_PESSOA'  => self::prepareCustomerType($order),
            'CL_IEST'    => self::prepareStateRegistration($order),
            'CL_MUNIC'   => self::prepareCity($order),
            'CL_UF'      => self::prepareState($order),
            'CL_PAIS'    => self::prepareCountry($order),
            'CL_FAX'     => '',
            'CL_TELEX'   => '',
            'CL_CXPOST'  => '',
            'CL_CODMSG'  => '',
            'CL_COMLJ'   => 'N',
        ];
    }

    private function shipping(array $totals): float
    {
        foreach ($totals as $total) {
            if ($total['code'] == 'shipping') {
                return $total['value'];
            }
        }

        return 0;
    }

    private function prepareSeparetedProducts(array $order): array
    {
        $data = [];

        $amountAll = 0;
        
        $weightAll = 0;

        $hasBc = false;

        foreach ($order['products'] as $product) {
            $amountAll += $product['total'];

            $weightAll += $product['weight'];

            if ($this->isBc($product)) {

                $hasBc = true;

                continue;
            }

        }

        $data = [
            'all' => [
                'amount' => $amountAll,
                'weight' => $weightAll ?? 1,
            ],
            'nonbc' => [
                'prefix' => '4',
                'amount' => $amountAll,
                'weight' => $weightAll ?? 1,
            ],
        ];

        $shipping_order = $this->shipping($order['totals']) / $data['all']['weight'];

        if ($hasBc) {
            $data['bc'] = [
                'prefix' => '4BC',
                'amount' => $amountAll,
                'weight' => $weightAll,
            ];

            $data['bc']['shipping_amount'] = $shipping_order * $data['bc']['weight']; 
        }

        $data['nonbc']['shipping_amount'] = $shipping_order * $data['nonbc']['weight']; 

        unset($data['all']);

        return $data;
    }

    private function getBillingData(array $order): array
    {
        $bills = self::prepareSeparetedProducts($order);

        $data = [];

        foreach ($bills as $bill) {
            $dueDate = $order['payment']['transaction']['due_date'] 
                ? Carbon::createFromFormat('Y-m-d', $order['payment']['transaction']['due_date'])->format('Ymd')
                : Carbon::now()->format('Ymd');

            $paidDate = $order['payment']['transaction']['paid_date'] 
                ? Carbon::createFromFormat('Y-m-d', $order['payment']['transaction']['paid_date'])->format('Ymd')
                : Carbon::now()->format('Ymd');

            $data[] = [
                'TI_APLIC' => Erp::applicationCode(),
                'TI_PREFIXO' => $bill['prefix'],
                'TI_NUM' => $this->orderId($order['order_id']),
                'TI_TIPO' => 'RA',
                'TI_CPFCNPJ' => self::prepareDocument($order),
                'TI_CC' => 'H2104',
                'TI_EMISSAO' => Carbon::parse($order['date_added'])->format('Ymd'),
                'TI_VENCTO' => $dueDate,
                'TI_VALOR' => $bill['amount'],
                'TI_XOBS' => $order['order_id'],
                'TI_DTPAG' => $paidDate,
                'TI_STATUS' => 'M',
                'TI_FRETE' => $bill['shipping_amount'],
                'TI_CNS' => '',
                'TI_DESPBAN' => '',
            ];
        }

        return $data;
    }

    public function formatOrderDataToErp(array $order)
    {        

        $data = [
            'billing' => $this->getBillingData($order),
            'customer' => $this->getCustomerData($order),            
        ];

       // dd($data);

        foreach ($order['products'] as $product) {
            $data['orders'][] = $this->getOrderData($order, $product);     
        }

        
        return $data;
    }

    private function getOrderData(array $order, array $product): array
    {
        $paymentData = $this->parsePaymentMethodData($order);

        $packageType = $this->packageType($order);

        return [
            'PV_APLIC'   => Erp::applicationCode(),
            'PV_CPFCNPJ' => self::prepareDocument($order),
            'PV_UF'      => $order['shipping']['zone_code'],
            'PV_NUMPED'  => $this->orderId($order['order_id']),
            'PV_CODPR'   => $product['options'][0]['sku'] ?? $product['sku'],
            'PV_DTHORA'  => Carbon::now()->format('Y-m-d H:i:s'),
            'PV_STATUS'  => 'M',
            'PV_TES'     => $this->tesCode($order, $product),
            'PV_QTDPED'  => $product['quantity'],
            'PV_PRUNIT'  => $product['price'],
            'PV_FTCONV'  => 0,
            'PV_TPCONV'  => '',
            'PV_CODMSG'  => $product['cest'] == 3 ? '012' : '',
            'PV_QTDVOL'  => 1,
            'PV_TRANSP'  => '000062', //pontual
            'PV_AR'      => $this->messageAr($order, $product),
            'PV_MEIOPG'  => $paymentData['paymentMethod'],
            'PV_CODCPG'  => $paymentData['paymentCode'],
            'PV_SEQUEN'  => '',
            'PV_DELET'   => '',
            'PV_TPINF'   => 0,
            'PV_PBRUTO'  => $this->totalWeight($order, 0),
            'PV_PESOL'   => $this->totalWeight($order, 0),
            'PV_FRETE'   => $this->shipping($order['totals']),
            'PV_ESPECV'  => $packageType['type'],
            'PV_OBJETO'  => $this->preparePvObject($order , $packageType),
        ];
    }


    public function preparePvObject(array $order, array $packageType): string
    {  
        if($order['order_status_id'] != Opencart::searchStatuses('after_dispatch_order')){
            return '';
        }else{
            return $this->packageDescription($order, $packageType);
        }   
       
    }

    private function packageDescription(array $order, array $packageType): string
    {
            
            $sexpOrder = Sexp::getOrder($order['order_id']);

            $tracking_number = $sexpOrder['numeroPostagem'];
            
            return "{$tracking_number} (" . number_format($packageType["width"], 2, '.', '') . " x " . number_format($packageType["height"], 2, '.', '') . " x " . number_format($packageType["length"], 2, '.', '') . ", " . number_format($this->totalWeight($order, $packageType['weight']), 3, '.', '') . ")";

        
    }

    private function totalWeight(array $order, $packageWeight)
    {
        $weight = $packageWeight;

        foreach ($order['products'] as $product) {
            $weight += $product['quantity'] * $product['weight'];
        }

        return $weight;
    }

    private function packageType(array $order)
    {
        $count = 0;

        foreach ($order['products'] as $product) {
            $count += $product['quantity'];
        }
        
        if ($count <= 2) {
            return [
                'type' => 'ENVELOPE',
                'height' => 20,
                'width' => 1,
                'length' => 28,
                'weight' => 0.022,
            ];
        } elseif ($count <= 5) {
            return [
                'type' => 'CAIXA',
                'height' => 9,
                'width' => 14,
                'length' => 18,
                'weight' => 0.086,
            ];
        } else {
            return [
                'type' => 'CAIXA',
                'height' => 9,
                'width' => 18,
                'length' => 27,
                'weight' => 0.142,
            ];
        } 
        
    }

    private function prepareDocument(array $order): string | null
    {

        $custom_fields = $order['custom_fields']??[];
        
        $document = null;
        if(self::customerIsPj($order)){
            $document = !empty(self::customerIsPj($order)) 
            ? Opencart::getCustomField($custom_fields, Opencart::cnpj()) 
            : null;
        }
        
        if(self::customerIsPf($order)){
            $document = !empty(self::customerIsPf($order)) 
            ? Opencart::getCustomField($custom_fields, Opencart::cpf()) 
            : null;
        }

        return valid::onlyNumbers($document);
    }

    public function logSendErp(string $log)
    {
        var_dump($log);

        $log = date("Y-m-d h:i:sa")." - ".$log ;
        $log = $log . PHP_EOL;
        File::append(
            storage_path('/logs/send_erp.log'), $log
       );
    }
   
}