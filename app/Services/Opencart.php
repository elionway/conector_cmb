<?php

namespace App\Services;

use App\Dto\Opencart\Order;
use App\Support\Erp;
use App\Models\Valid;
use App\Console\Commands\SendOrdersToErp;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class Opencart {

    protected int $order_id = 0;

    protected string $api_url = '';

    private string $consumer_key = '';
    
    private string $consumer_secret = '';

    private string $access_token = '';

    protected int $complete_status_id = 0;

    protected array $search_statuses = [];

    protected array|string $cpf = '';

    protected array|string $cnpj = '';

    protected array|string $state_registration = '';

    protected array|string $address_number = '';

    protected array|string $address_complement = '';

    protected array|string $neighborhood = '';

    protected array|string $text_not_blank;

    protected array|string $text_max_char;

    protected array|string $text_min_char;

    protected array|string $text_string_erp;

    protected array|string $text_string_erp_endereco;

    protected array $error_validate_data = [];


    public function __construct()
    {
        $this->validateSettings();

        $this->api_url = config('connector.opencart_url');
    
        $this->consumer_key = config('connector.consumer_key');

        $this->consumer_secret = config('connector.consumer_secret');

        $this->search_statuses = config('connector.search_status');

        $this->update_statuses = config('connector.update_status');


        $this->cpf = config('connector.custom_fields')['cpf'];

        $this->cnpj = config('connector.custom_fields')['cnpj'];

        $this->state_registration = config('connector.custom_fields')['state_registration'];

        $this->address_number = config('connector.custom_fields')['address_number'];

        $this->address_complement = config('connector.custom_fields')['address_complement'];

        $this->birthdate = config('connector.custom_fields')['birthdate'];

        $this->branch = config('connector.custom_fields')['branch'];

        $this->registration = config('connector.custom_fields')['registration'];

        $this->cellphone = config('connector.custom_fields')['cellphone'];

        $this->accessToken();

        $this->text_not_blank = "%s - Valor não pode estar em branco";
        $this->text_max_char = "%s - Valor incorreto. Máx. Char: %s . Valor Obtido::: \"%s\"";
        $this->text_min_char = "%s - Valor incorreto. Min. Char: %s . Valor Obtido::: \"%s\"";
        $this->text_string_erp = "%s - Valor incorreto. Somente letras números ponto(.), hífen(-) e barra(/) são permitidos. Valor Obtido::: \"%s\"";
        $this->text_string_erp_endereco = "%s - Valor incorreto. Somente letras números virgula(,), ponto(.), hífen(-) e barra(/) são permitidos. Valor Obtido::: \"%s\"";

    }

    /**
     * @param ?array $queryParams
     * @return Order[]
     */
    public function orders(?array $queryParams = [])
    {
        $response = $this->execute('order', 'get', null, $queryParams);

        $orders = [];
        $orders_valited = [];

        $i = 1;
        $limit = 99;

        if (!empty($response['records'])) {            

            $orders = $response['records'];
            $permited_orders = [];

            foreach ($orders as $key => $order) {                               

                //dd($order);
                #var_dump('INICIOU: '.$order['order_id']);     

                if(!empty($permited_orders) && !in_array($order['order_id'], $permited_orders)){
                    var_dump('Pulou: '.$order['order_id']);
                    continue;
                }
               
                $this->order_id = $order['order_id'];

                if($i > $limit){ break; } $i++;

                $order['products'] = $this->orderProductsMerge($order['products']);

                if($this->validateOrderDataToErpOrChangeOrderStatusToErrorAndFail($order)){
                 #   var_dump('VALIDOU: '.$order['order_id']);      
                    $orders_valited[$key] = $order;
                }else{           
                    $this->logValidateData('NÃO VALIDOU: '.$order['order_id']);
                }
                
               $this->changeOrdersNotValited();
                //$this->error_validate_data = [];

            }
        }
        return $orders_valited;
     //   return collect($orders_valited);
    }

    public function orderProductsMerge(array $order_products)
    {
        $products = [];

        foreach($order_products as $order_product_key => $order_product){

            $merge = false;
            $key_for_edit = '';

            foreach($products as $product_key => $product){
                if($order_product['product_id'] == $product['product_id']
                    && $order_product['sku']     == $product['sku'] 
                    && $order_product['options'] == $product['options']){
                    $merge = true;
                    $key_for_edit = $product_key;
                    break;
                }
            }

            if($merge = true && $key_for_edit !== '' && !empty($products[$key_for_edit]['product_id']) ){

                $products[$key_for_edit]['quantity']  += $order_product['quantity'];
                $products[$key_for_edit]['total']     += $order_product['total'];
                $products[$key_for_edit]['tax']       += $order_product['tax'];
                $products[$key_for_edit]['reward']    += $order_product['reward'];
                $products[$key_for_edit]['weight']    += $order_product['weight'];

            }else{
                $products[] = $order_product;
            }

        }

       // dd($products);

        return $products;
    }

    public function validateOrderDataToErpOrChangeOrderStatusToErrorAndFail(array $order)
    {

        $error = [];
        if( !($order['order_status_id'] == Opencart::searchStatuses('new_order') 
        || $order['order_status_id'] == Opencart::searchStatuses('reimport_new_order')
        || $order['order_status_id'] == Opencart::searchStatuses('after_dispatch_order') )){
            return  true;
        }

        $order_formated_data = Erp::formatOrderDataToErp($order);
        $order_id = $order['order_id'];
       
        $customer =  $order_formated_data['customer']??[];
        $order_products =  $order_formated_data['orders']??[];
        $billing =  $order_formated_data['billing']??[];
       
        $error = [];

        if(empty($customer)){
            $error[] = "Ocorreu um erro com a criação do cliente";
            return  $error;
        }
        
        if(!empty($error['customer'])){
            $error['customer'] = array_merge($error['customer'], $this->validateAplic($customer, 'CL_APLIC'));
        }else{
            if(!empty($this->validateAplic($customer, 'CL_APLIC'))){
                
                $error['customer'] = $this->validateAplic($customer, 'CL_APLIC');
            }
        }
        
        if(empty($customer['CL_STATUS'])){
            $error['customer'][] = sprintf($this->text_not_blank, 'CL_STATUS' );
        }

        if(!empty($customer['CL_STATUS']) && $customer['CL_STATUS'] != 'M'){
            $error['customer'][] = "CL_STATUS - Valor incorreto. Espetado ECMB, Valor Obtido:: \"{$customer['CL_STATUS']}\"";
        }

        if(!empty($customer['CL_STATUS']) && strlen($customer['CL_STATUS']) > 1){
            $error['customer'][] = sprintf($this->text_max_char, 'CL_STATUS', '1',  $customer['CL_STATUS']);
        }

        
        if(empty($customer['CL_CPFCNPJ'])){
            $error['customer'][] = sprintf($this->text_not_blank, 'CL_CPFCNPJ' );
        }else{
                       
            if(valid::verifica_cpf_cnpj($customer['CL_CPFCNPJ']) === 'CPF'){
                if(!valid::valida_cpf($customer['CL_CPFCNPJ'])){                        
                    $error['customer'][] = "CL_CPFCNPJ - Valor incorreto. CPF Inválido Valor Obtido:: \"{$customer['CL_CPFCNPJ']}\"";
                }

                if(empty($customer['CL_CPFCNPJ']) || $customer['CL_PESSOA'] != 'F'){                        
                    $error['customer'][] = "CL_PESSOA CL_CPFCNPJ - Valor incorreto. CPF ou tipo de pessoa inválidos. : Valor Obtido: L_CPFCNPJ=>\"{$customer['CL_CPFCNPJ']}\", CL_PESSOA=>\"{$customer['CL_PESSOA']}\"";
                }

                if(empty($customer['CL_CPFCNPJ']) || $customer['CL_SIMPLES'] != '2'){                        
                    $error['customer'][] = "CL_SIMPLES CL_CPFCNPJ - Valor incorreto. Simples Não permitido para CPF. : Valor Obtido: L_CPFCNPJ=>\"{$customer['CL_CPFCNPJ']}\", CL_SIMPLES=>\"{$customer['CL_SIMPLES']}\"";
                }

            }

            if(valid::verifica_cpf_cnpj($customer['CL_CPFCNPJ']) === 'CNPJ'){

                if(!valid::valida_cnpj($customer['CL_CPFCNPJ'])){                        
                    $error['customer'][] = "CL_CPFCNPJ - Valor incorreto. CNPJ Inválido Valor Obtido:: \"{$customer['CL_CPFCNPJ']}\"";
                }

                if(empty($customer['CL_CPFCNPJ']) || $customer['CL_PESSOA'] != 'J'){                        
                    $error['customer'][] = "CL_PESSOA CL_CPFCNPJ - Valor incorreto. CNPJ ou tipo de pessoa inválidos. : Valor Obtido: L_CPFCNPJ=>\"{$customer['CL_CPFCNPJ']}\", CL_PESSOA=>\"{$customer['CL_PESSOA']}\"";
                }
            }

        }           

        if(empty($customer['CL_NOME'])){
            $error['customer'][] = sprintf($this->text_not_blank, 'CL_NOME' );
        }

        if(!empty($customer['CL_NOME']) && strlen($customer['CL_NOME']) > 60){
            $error['customer'][] = sprintf($this->text_max_char, 'CL_NOME', '60',  $customer['CL_NOME']);
        }     

        if(!empty($customer['CL_NOME']) &&  valid::stringErp($customer['CL_NOME']) ){
            $error['customer'][] = sprintf($this->text_string_erp, 'CL_NOME',   $customer['CL_NOME']);
        }       

        if(empty($customer['CL_PESSOA'])){
            $error['customer'][] = sprintf($this->text_not_blank, 'CL_PESSOA' );
        }

        if(!empty($customer['CL_PESSOA']) && !($customer['CL_PESSOA'] == 'J' || $customer['CL_PESSOA'] == 'F')){
            $error['customer'][] = "CL_PESSOA - Valor incorreto. Espetado F ou J, Valor Obtido:: \"{$customer['CL_PESSOA']}\"";
        }

        if(empty($customer['CL_UF'])){
            $error['customer'][] = sprintf($this->text_not_blank, 'CL_UF' );
        }

        if(!empty($customer['CL_UF']) && strlen($customer['CL_UF']) > 2){
            $error['customer'][] = sprintf($this->text_max_char, 'CL_UF', '2',  $customer['CL_UF']);
        } 

        if(!empty($customer['CL_UF']) && !valid::ufSigla($customer['CL_UF'])){
            $error['customer'][] = "CL_UF - Valor incorreto. Valor Obtido::: \"{$customer['CL_UF']}\"";
        }else{
            if(!empty($customer['CL_IEST']) && strlen($customer['CL_IEST']) > 18){
                $error['customer'][] = sprintf($this->text_max_char, 'CL_IEST', '18',  $customer['CL_IEST']);
            } 
        }

        if(!empty($customer['CL_IEST']) && !valid::inscricao_estadual($customer['CL_IEST'], $customer['CL_UF'])){
            $error['customer'][] = "CL_IEST - Valor incorreto. Iscrição estadual inválida. Valor Obtido:: CL_IEST: \"{$customer['CL_IEST']}\", CL_UF: \"{$customer['CL_UF']}\"";
        } 

        if(empty($customer['CL_END'])){
            $error['customer'][] = sprintf($this->text_not_blank, 'CL_END' );
        }

        if(!empty($customer['CL_END']) && strlen($customer['CL_END']) > 60){
            $error['customer'][] = sprintf($this->text_max_char, 'CL_END', '60',  $customer['CL_END']);
        } 
        
        if(!empty($customer['CL_END']) &&  valid::stringEnderecoErp($customer['CL_END']) ){
            $error['customer'][] = sprintf($this->text_string_erp_endereco, 'CL_END',   $customer['CL_END']);
        }

        $address_slices = explode(',', $customer['CL_END']);
        if(!empty($customer['CL_END']) &&  count($address_slices) > 2 ){
            $error['customer'][] = "CL_END - Valor incorreto. Formato do Endereco incorreto. Valor Obtido:: CL_END: \"{$customer['CL_END']}\"";
        }

        $address_slices_1 = trim($address_slices[1]??'');
        $address_slices_1_slices = explode(" ", $address_slices_1);

        $address_slices_1_slices_0 = $address_slices_1_slices[0];

        if(!empty($customer['CL_END']) && !(empty($address_slices_1_slices_0) || is_numeric($address_slices_1_slices_0))){
            $error['customer'][] = "CL_END - Valor incorreto. Formato do Endereco incorreto. Depois da virgula é obrigatorio um numero. Valor Obtido:: CL_END: \"{$customer['CL_END']}\"";
        }

        if(empty($customer['CL_MUNIC'])){
            $error['customer'][] = sprintf($this->text_not_blank, 'CL_MUNIC' );
        }

        if(!empty($customer['CL_MUNIC']) && strlen($customer['CL_MUNIC']) > 32){
            $error['customer'][] = sprintf($this->text_max_char, 'CL_MUNIC', '32',  $customer['CL_MUNIC']);
        }

        if(!empty($customer['CL_MUNIC']) &&  valid::stringErp($customer['CL_MUNIC']) ){
            $error['customer'][] = sprintf($this->text_string_erp, 'CL_MUNIC',   $customer['CL_MUNIC']);
        } 

        if(empty($customer['CL_BAIRRO'])){
            $error['customer'][] = sprintf($this->text_not_blank, 'CL_BAIRRO' );
        }

        if(!empty($customer['CL_BAIRRO']) && strlen($customer['CL_BAIRRO']) > 30){
            $error['customer'][] = sprintf($this->text_max_char, 'CL_BAIRRO', '30',  $customer['CL_BAIRRO']);
        }

        if(!empty($customer['CL_BAIRRO']) &&  valid::stringErp($customer['CL_BAIRRO']) ){
            $error['customer'][] = sprintf($this->text_string_erp, 'CL_BAIRRO',   $customer['CL_BAIRRO']);
        } 

        if(empty($customer['CL_CEP'])){
            $error['customer'][] = sprintf($this->text_not_blank, 'CL_CEP' );
        }

        if(!empty($customer['CL_CEP']) && strlen($customer['CL_CEP']) > 8){
            $error['customer'][] = sprintf($this->text_max_char, 'CL_CEP', '8',  $customer['CL_CEP']);
        }

        $cep_consult = valid::consultCep($customer['CL_CEP']);
        
        if(!empty($customer['CL_CEP']) && empty($cep_consult['cep']) ){
            $error['customer'][] =  "CL_CEP - Valor incorreto. CEP Inválido. Valor Obtido: \"{$customer['CL_CEP']}\"";
        }
      
        if(empty($customer['CL_DDD'])){
            $error['customer'][] = sprintf($this->text_not_blank, 'CL_DDD' );
        }

        if(!empty($customer['CL_DDD']) && strlen($customer['CL_DDD']) > 2){
            $error['customer'][] = sprintf($this->text_max_char, 'CL_DDD', '2',  $customer['CL_DDD']);
        }

        if(!empty($customer['CL_DDD']) && !valid::ddd($customer['CL_DDD'])){
            $error['customer'][] = "CL_DDD - Valor incorreto. DDD Inválido. Valor Obtido: \"{$customer['CL_DDD']}\"";
        }

        if(empty($customer['CL_TEL'])){
            $error['customer'][] = sprintf($this->text_not_blank, 'CL_TEL' );
        }

        if(!empty($customer['CL_TEL']) && strlen($customer['CL_TEL']) < 8){
            $error['customer'][] = sprintf($this->text_min_char, 'CL_TEL', '8',  $customer['CL_TEL']);            
        }

        if(!empty($customer['CL_TEL']) && strlen($customer['CL_TEL']) == 8){

            if(in_array(substr($customer['CL_TEL'], 0, 1), [9,8,7])){
                $error['customer'][] = "CL_TEL - Valor incorreto. Telefones iniciados com 7,8 ou 9 são classificados como celular e devem conter 9 digitos. Valor Obtido: \"{$customer['CL_TEL']}\"";
            }elseif(!in_array(substr($customer['CL_TEL'], 0, 1), [2,3,4])){
                $error['customer'][] = "CL_TEL - Valor incorreto. Telefones devem iniciar com 2,3 ou 4. Valor Obtido: \"{$customer['CL_TEL']}\"";
            }

        }        

        if(!empty($customer['CL_TEL']) && strlen($customer['CL_TEL']) > 9){
            $error['customer'][] = sprintf($this->text_max_char, 'CL_TEL', '9',  $customer['CL_TEL']);
        }

        if(!empty($customer['CL_TEL']) && !valid::isOnlyNumber($customer['CL_TEL'])){
            $error['customer'][] = "CL_TEL - Valor incorreto. Permite somente números. Valor Obtido: \"{$customer['CL_TEL']}\"";
        }       

        if(!empty($customer['CL_FAX']) && strlen($customer['CL_FAX']) > 15){
            $error['customer'][] = sprintf($this->text_max_char, 'CL_FAX', '15',  $customer['CL_FAX']);            
        }

        if(!empty($customer['CL_FAX']) && !valid::isOnlyNumber($customer['CL_FAX'])){
            $error['customer'][] = "CL_FAX - Valor incorreto. Permite somente números. Valor Obtido: \"{$customer['CL_FAX']}\"";
        }

        if(!empty($customer['CL_TELEX']) && strlen($customer['CL_TELEX']) > 10){
            $error['customer'][] = sprintf($this->text_max_char, 'CL_TELEX', '10',  $customer['CL_TELEX']);            
        }

        if(!empty($customer['CL_CXPOST']) && strlen($customer['CL_CXPOST']) > 10){
            $error['customer'][] = sprintf($this->text_max_char, 'CL_CXPOST', '10',  $customer['CL_CXPOST']);            
        }

        if(empty($customer['CL_EMAIL'])){
            $error['customer'][] = sprintf($this->text_not_blank, 'CL_EMAIL' );
        }

        if(!empty($customer['CL_EMAIL']) && (!filter_var($customer['CL_EMAIL'], FILTER_VALIDATE_EMAIL) || !valid::validateEmail($customer['CL_EMAIL']))){
            $error['customer'][] = "CL_EMAIL - Valor incorreto. E-mail inválido. Valor Obtido: \"{$customer['CL_EMAIL']}\"";       
        }

        if(!empty($customer['CL_CODMSG'])){
            $error['customer'][] = "CL_CODMSG - Valor incorreto. Deve ser gravado em branco. Valor Obtido: \"{$customer['CL_CODMSG']}\"";    
        }

        if(empty($customer['CL_COMLJ']) || $customer['CL_COMLJ'] != "N"){
            $error['customer'][] = "CL_COMLJ - Valor incorreto. Deve Igual a \"N\". Valor Obtido: \"{$customer['CL_COMLJ']}\"";    
        }

        if(empty($customer['CL_PAIS']) || valid::stringErp($customer['CL_PAIS']) ){
            $error['customer'][] = "CL_PAIS - Valor incorreto. Deve Igual a \"N\". Valor Obtido: \"{$customer['CL_PAIS']}\"";    
        }

        if(!empty($customer['CL_DELET'])){
            $error['customer'][] = "CL_DELET - Valor incorreto. Deve ser gravado em branco. Valor Obtido: \"{$customer['CL_DELET']}\"";
        }

        if(empty($customer['CL_CODMUN'])){
            $error['customer'][] = sprintf($this->text_not_blank, 'CL_CODMUN' );
        }

        if(!empty($customer['CL_CODMUN']) && strlen($customer['CL_CODMUN']) > 5){
            $error['customer'][] = sprintf($this->text_max_char, 'CL_CODMUN', '5',  $customer['CL_CODMUN']); 
        }

        if(!empty($customer['CL_CODMUN']) && !valid::isOnlyNumber($customer['CL_CODMUN'])){
            $error['customer'][] = "CL_CODMUN - Valor incorreto. Permite somente números. Valor Obtido: \"{$customer['CL_CODMUN']}\"";
        }

        if(empty($customer['CL_CODPAIS'])){
            $error['customer'][] = sprintf($this->text_not_blank, 'CL_CODPAIS' );
        }

        if(!empty($customer['CL_CODPAIS']) && !($customer['CL_CODPAIS'] === '01058')){
            $error['customer'][] = "CL_CODPAIS - Valor incorreto. Permite somente \"01058\". Valor Obtido: \"{$customer['CL_CODPAIS']}\"";
        }

        if(empty($customer['CL_SIMPLES']) || !in_array($customer['CL_SIMPLES'], [1,2] )){
            $error['customer'][] = "CL_SIMPLES - Valor incorreto. Permite somente \"1=>sim\" ou \"2=>não\". Valor Obtido: \"{$customer['CL_SIMPLES']}\"";              
        }

        if(empty($customer['CL_INCCULT'])){
            $error['customer'][] = sprintf($this->text_not_blank, 'CL_INCCULT' );
        }

        if(!empty($customer['CL_INCCULT']) && !in_array($customer['CL_INCCULT'], [1,2] )){
            $error['customer'][] = "CL_INCCULT - Valor incorreto. Permite somente \"1=>sim\" ou \"2=>não\". Valor Obtido: \"{$customer['CL_INCCULT']}\"";              
        }

        //ORDER PRODUCTS

        foreach($order_products as $key => $order_product){

            if(empty($order_product['PV_CODPR'])){
                $error['products'][$key][] = sprintf($this->text_not_blank, 'PV_CODPR' );
            }elseif(!empty($order_product['PV_CODPR']) && strlen($order_product['PV_CODPR']) > 15){
                $error['products'][$key][] = sprintf($this->text_max_char, 'PV_CODPR', '15',  $order_product['PV_CODPR']);
            }else{
                $key = $order_product['PV_CODPR'];
            }

            if(!empty($this->validateAplic( $order_product, 'PV_APLIC'))){

                if(!empty($error['products'][$key])){
                    $error['products'][$key] = array_merge($error['products'][$key], $this->validateAplic($order_product, 'PV_APLIC'));
                }else{
                    $error['products'][$key] = $this->validateAplic($order_product, 'PV_APLIC');
                }

            }

            if(empty($order_product['PV_CPFCNPJ'])){
                $error['products'][$key][] = sprintf($this->text_not_blank, 'PV_CPFCNPJ' );
            }  

            if(!empty($order_product['PV_CPFCNPJ']) && $order_product['PV_CPFCNPJ'] != $customer['CL_CPFCNPJ']){
                $error['products'][$key][] =  "PV_CPFCNPJ CL_CPFCNPJ - CPFCPNJ no Produto é diferente do Cliente. Valor Obtido PV_CPFCNPJ: \"{$order_product['PV_CPFCNPJ']}\" CL_CPFCNPJ: \"{$customer['CL_CPFCNPJ']}\"";              
            } 

            if(empty($order_product['PV_UF'])){
                $error['products'][$key][] = sprintf($this->text_not_blank, 'PV_UF' );
            }
    
            if(!empty($order_product['PV_UF']) && strlen($order_product['PV_UF']) > 2){
                $error['products'][$key][] = sprintf($this->text_max_char, 'PV_UF', '2',  $order_product['PV_UF']);
            }

            if(empty($order_product['PV_NUMPED'])){
                $error['products'][$key][] = sprintf($this->text_not_blank, 'PV_NUMPED' );
            }
    
            if(!empty($order_product['PV_NUMPED']) && strlen($order_product['PV_NUMPED']) > 9){
                $error['products'][$key][] = sprintf($this->text_max_char, 'PV_NUMPED', '9',  $order_product['PV_NUMPED']);
            }

            if(empty($order_product['PV_QTDPED'])){
                $error['products'][$key][] = sprintf($this->text_not_blank, 'PV_QTDPED' );
            }
    
            if(!empty($order_product['PV_QTDPED']) && !strlen($order_product['PV_QTDPED']) > 0){
                $error['products'][$key][] = "PV_QTDPED - Quantidade inválida. Valor Obtido: PV_QTDPED: \"{$order_product['PV_QTDPED']}\"";              
            }

            if(empty($order_product['PV_PRUNIT'])){
                $error['products'][$key][] = sprintf($this->text_not_blank, 'PV_PRUNIT' );
            }
    
            if(!empty($order_product['PV_PRUNIT']) && !strlen($order_product['PV_PRUNIT']) > 0){
                $error['products'][$key][] = "PV_PRUNIT - Quantidade inválida. Valor Obtido: PV_PRUNIT: \"{$order_product['PV_PRUNIT']}\"";              
            }

            if(!empty($order_product['PV_FTCONV']) && $order_product['PV_FTCONV'] === 0){
                $error['products'][$key][] = "PV_FTCONV - Valor inválido. Deve ser 0. Valor Obtido: PV_FTCONV: \"{$order_product['PV_FTCONV']}\"";    
            }
    
            if(!empty($order_product['PV_TPCONV']) && $order_product['PV_TPCONV'] === ''){
                $error['products'][$key][] = "PV_TPCONV - Valor inválido. Deve ser \"em branco\". Valor Obtido: PV_TPCONV: \"{$order_product['PV_TPCONV']}\"";    
            }
            
            if(empty($order_product['PV_PBRUTO'])){
                $error['products'][$key][] = sprintf($this->text_not_blank, 'PV_PBRUTO' );
            }
    
            if(!empty($order_product['PV_PBRUTO']) && !strlen($order_product['PV_PBRUTO']) > 0){
                $error['products'][$key][] = "PV_PBRUTO - Valor deve ser maior que 0. Valor Obtido: PV_PBRUTO: \"{$order_product['PV_PBRUTO']}\"";              
            }

            if(empty($order_product['PV_PESOL'])){
                $error['products'][$key][] = sprintf($this->text_not_blank, 'PV_PESOL' );
            }
    
            if(!empty($order_product['PV_PESOL']) && !strlen($order_product['PV_PESOL']) > 0){
                $error['products'][$key][] = "PV_PESOL - Valor deve ser maior que 0. Valor Obtido: PV_PESOL: \"{$order_product['PV_PESOL']}\"";              
            }

            if(isset($order_product['PV_CODMSG']) && !($order_product['PV_CODMSG'] ==='' || $order_product['PV_CODMSG'] =='012')){
                $error['products'][$key][] = "PV_CODMSG - Valor permitido é \"012\" ou em branco. Valor Obtido: PV_CODMSG: \"{$order_product['PV_CODMSG']}\"";
            }

            //TODO: refatorar PV_CODCPG com mais validações
            
            if(empty($order_product['PV_CODCPG'])){
                $error['products'][$key][] = sprintf($this->text_not_blank, 'PV_CODCPG' );
            }

            if(!empty($order_product['PV_CODCPG'])) {
                if( !valid::validateExistPaymentCodes($order_product['PV_CODCPG'])){
                    $error['products'][$key][] = "PV_CODCPG - Valor incorreto. Valor Obtido: \"{$order_product['PV_CODCPG']}\"";              
                }

                if(empty($order['payment']['transaction']['payment_code'])){
                    $error['products'][$key][] = "ORDER Transaction - Pedido sem codigo de pagamento.";        
                }              
                           
            }

            if(empty($order_product['PV_TRANSP'])){
                $error['products'][$key][] = sprintf($this->text_not_blank, 'PV_TRANSP' );
            }

            if(!empty($order_product['PV_TRANSP']) && !($order_product['PV_TRANSP'] === "000062")){
                $error['products'][$key][] = "PV_TRANSP - Valor incorreto. Valor permitido é \"000062\". Valor Obtido: \"{$order_product['PV_TRANSP']}\"";
            }

            //TODO: refatorar PV_TES com mais validações

            if(empty($order_product['PV_TES'])){
                $error['products'][$key][] = sprintf($this->text_not_blank, 'PV_TES' );
            }

            if(!empty($order_product['PV_TES']) && !valid::inTes($order_product['PV_TES'])){
                $error['products'][$key][] = "PV_TES - Valor incorreto. Valores permitidos são ".implode(", ", config('connector.erp_tes')).". Valor Obtido: \"{$order_product['PV_TES']}\"";
            }

            if(empty($order_product['PV_QTDVOL'])){
                $error['products'][$key][] = sprintf($this->text_not_blank, 'PV_QTDVOL' );
            }
            
            if(!empty($order_product['PV_QTDVOL']) && $order_product['PV_QTDVOL'] != 1){
                $error['products'][$key][] =  "PV_QTDVOL - Valor incorreto. Valores deve ser iqual a 1. Valor Obtido: \"{$order_product['PV_QTDVOL']}\"";
            }

            //TODO: refatorar PV_ESPECV com mais validações

            if(empty($order_product['PV_ESPECV'])){
                $error['products'][$key][] = sprintf($this->text_not_blank, 'PV_ESPECV' );
            }

            if(!empty($order_product['PV_ESPECV']) && strlen($order_product['PV_ESPECV']) > 10){
                $error['products'][$key][] = sprintf($this->text_max_char, 'PV_ESPECV', '10',  $order_product['PV_ESPECV']);
            }

            //TODO: refatorar PV_ESPECV com mais validações

            if(empty($order_product['PV_AR'])){
                $error['products'][$key][] = sprintf($this->text_not_blank, 'PV_AR' );
            }

            if(!empty($order_product['PV_AR']) && strlen($order_product['PV_AR']) > 508){
                $error['products'][$key][] = sprintf($this->text_max_char, 'PV_AR', '508',  $order_product['PV_AR']);
            }

            if(!empty($customer['PV_SEQUEN'])){
                $error['products'][$key][] = "PV_SEQUEN - Valor incorreto. Deve ser gravado em branco. Valor Obtido: \"{$order_product['PV_SEQUEN']}\"";    
            }

            if(!empty($customer['PV_DELET'])){
                $error['products'][$key][] = "PV_DELET - Valor incorreto. Deve ser gravado em branco. Valor Obtido: \"{$order_product['PV_DELET']}\"";    
            }

            //TODO: refatorar PV_TPINF com mais validações
            // if(isset($order_product['PV_TPINF']) && $order_product['PV_TPINF'] === 0){
            //     $error['products'][$key][] = "PV_TPINF - Valor incorreto. Deve ser gravado 0 (zero). Valor Obtido: \"{$order_product['PV_TPINF']}\"";    
            // }

            if(empty($order_product['PV_DTHORA'])){
                $error['products'][$key][] = sprintf($this->text_not_blank, 'PV_DTHORA' );
            }

            if(empty($order_product['PV_STATUS'])){
                $error['products'][$key][] = sprintf($this->text_not_blank, 'PV_STATUS' );
            }

            if(!empty($order_product['PV_STATUS']) && $order_product['PV_STATUS'] != 'M'){
                $error['products'][$key][] = "PV_STATUS - Valor incorreto. Deve ser gravado M. Valor Obtido: \"{$order_product['PV_STATUS']}\"";    
            }

            //TODO: refatorar PV_OBJETO com mais validações

            // if(empty($order_product['PV_OBJETO'])){
            //     $error['products'][$key][] = sprintf($this->text_not_blank, 'PV_OBJETO' );
            // }

            // if(!empty($order_product['PV_OBJETO']) && strlen($order_product['PV_OBJETO']) > 20){
            //     $error['products'][$key][] = sprintf($this->text_max_char, 'PV_OBJETO', '20',  $order_product['PV_OBJETO']);
            // }

            if(empty($order_product['PV_MEIOPG'])){
                $error['products'][$key][] = sprintf($this->text_not_blank, 'PV_MEIOPG' );
            }

            if(!empty($order_product['PV_MEIOPG']) 
                && !empty($order['payment']['transaction']['payment_code']) 
                && config('connector.payment_methods')[$order['payment']['transaction']['payment_code']] != $order_product['PV_MEIOPG']){
                $error['products'][$key][] = "PV_MEIOPG - Valor incorreto. Codigo de pagament divergente. Para o \"".$order['payment']['transaction']['payment_code']."\" deve ser gravado \"".config('connector.payment_methods')[$order['payment']['transaction']['payment_code']]."\". Valor Obtido: \"{$order_product['PV_MEIOPG']}\"";    
            }
        }

        //VALIDATE TITULOS
       
        foreach($billing as $key => $billing_item){        

           if(!empty($this->validateAplic( $billing_item, 'TI_APLIC'))){

                if(!empty($error['billing'][$key])){
                    $error['billing'][$key] = array_merge($error['billing'][$key], $this->validateAplic($billing_item, 'TI_APLIC'));
                }else{
                    $error['billing'][$key] = $this->validateAplic($billing_item, 'TI_APLIC');
                }

            }

            if(empty($billing_item['TI_PREFIXO'])){
                $error['billing'][$key][] = sprintf($this->text_not_blank, 'TI_PREFIXO' );
            }else{
                $key = $billing_item['TI_PREFIXO'];
            }

            // if(!empty($billing_item['TI_PREFIXO']) && in_array($billing_item['TI_PREFIXO'], ['4BC', '4'])){
            //     $error['billing'][$key][] = "TI_PREFIXO - Valor incorreto. Deve ser gravado '4BC' Produtos BC ou '4' Outros produtos. Valor Obtido: \"{$billing_item['TI_PREFIXO']}\"";    
            // }
            
            if(empty($billing_item['TI_NUM'])){
                $error['billing'][$key][] = sprintf($this->text_not_blank, 'TI_NUM' );
            }

            if(!empty($billing_item['TI_NUM']) && strlen($billing_item['TI_NUM']) > 9){
                $error['billing'][$key][] = sprintf($this->text_max_char, 'TI_NUM', '9',  $billing_item['TI_NUM']);
            }

            if(empty($billing_item['TI_TIPO'])){
                $error['billing'][$key][] = sprintf($this->text_not_blank, 'TI_TIPO' );
            }

            if(!empty($billing_item['TI_TIPO']) && $billing_item['TI_TIPO'] != "RA"){
                $error['billing'][$key][] = "TI_TIPO - Valor incorreto. Deve ser gravado 'RA'. Valor Obtido: \"{$billing_item['TI_TIPO']}\"";  
            }

            if(empty($billing_item['TI_CPFCNPJ'])){
                $error['billing'][$key][] = sprintf($this->text_not_blank, 'TI_CPFCNPJ' );
            }  

            if(!empty($billing_item['TI_CPFCNPJ']) && $billing_item['TI_CPFCNPJ'] != $customer['CL_CPFCNPJ']){
                $error['billing'][$key][] =  "TI_CPFCNPJ CL_CPFCNPJ - CPFCPNJ no Titulo é diferente do Cliente. Valor Obtido TI_CPFCNPJ: \"{$billing_item['TI_CPFCNPJ']}\" CL_CPFCNPJ: \"{$customer['CL_CPFCNPJ']}\"";              
            } 

            if(empty($billing_item['TI_CC'])){
                $error['billing'][$key][] = sprintf($this->text_not_blank, 'TI_CC' );
            }

            if(!empty($billing_item['TI_CC']) && $billing_item['TI_CC'] != "H2104"){
                $error['billing'][$key][] = "TI_CC - Valor incorreto. Deve ser gravado 'H2104'. Valor Obtido: \"{$billing_item['TI_CC']}\"";  
            }

            if(empty($billing_item['TI_DTPAG'])){
                $error['billing'][$key][] = sprintf($this->text_not_blank, 'TI_DTPAG' );
            }

            if(!empty($billing_item['TI_DTPAG']) && strlen($billing_item['TI_DTPAG']) != 8){
                $error['billing'][$key][] = "TI_DTPAG - Valor incorreto. Deve ser estar no seguinte formato 20221231. Valor Obtido: \"{$billing_item['TI_DTPAG']}\"";  
            }

            if(empty($billing_item['TI_STATUS'])){
                $error['billing'][$key][] = sprintf($this->text_not_blank, 'TI_STATUS' );
            }

            if(!empty($billing_item['TI_STATUS']) && $billing_item['TI_STATUS'] != "M"){
                $error['billing'][$key][] = "TI_STATUS - Valor incorreto. Deve ser gravado 'M'. Valor Obtido: \"{$billing_item['TI_STATUS']}\"";  
            }

             //TODO: refatorar TI_FRETE com mais validações

        }

        $this->error_validate_data = $error;       
        
        
        return !$error;        
    }

    public function changeOrdersNotValited(){

        $error = $this->error_validate_data;
        $order_id = $this->order_id;

        if(!empty($error)){
           $comment = "Erro com os seguinte dados: <br>";

           if(!empty($error['customer'])) {
                $comment .= "<br><p>";
                $comment .= "<b>NO CLIENTE</b>";
                $comment .= "<ul>";
                foreach($error['customer'] as $errors_code_c => $errors_customer){
                    $comment .= "<li>".print_r($errors_customer, true)."</li>";
                }
                $comment .= "</ul>";
                $comment .= "</p>";
            }

            if(!empty($error['products'])) {
                $comment .= "<br><p>";
                $comment .= "<b>NOS PRODUTOS</b>";
                foreach($error['products'] as $errors_code_p => $errors_products){
                    $comment .= "<br>&nbsp;".print_r($errors_code_p, true);
                    $comment .= "<ul>";
                    foreach($errors_products as $error_products){
                        $comment .= "<li>".print_r($error_products, true)."</li>";
                    }
                    $comment .= "</ul>";
                }
                $comment .= "</p>";
            }

            if(!empty($error['billing'])) {
                $comment .= "<br><p>";
                $comment .= "<b>NOS TITULOS</b>";
                foreach($error['billing'] as $errors_code_b => $errors_billing){
                    $comment .= "<br> Prefixo ".print_r($errors_code_b, true);
                    $comment .= "<ul>";
                    foreach($errors_billing as $error_billing){
                        $comment .=  "<li>".print_r($error_billing, true)."</li>";
                    }
                    $comment .= "</ul>";
                }
                $comment .= "</p>";
            }

            self::sendChangeOrdersNotValited($order_id, $comment);
           
        }

    }
    public function sendChangeOrdersNotValited($order_id, $comment)
    {

        $this->logValidateData($comment);

        self::orderHistory($order_id, [
        
            'order_status_id' => Opencart::updateStatuses('with_error_item_data'),
            'comment' => $comment,
            'notify' => false,

        ]);

     
    }
    public function order(string|int $order_id)
    {
        $order = $this->execute("order/{$order_id}");

       // $response = $this->prepareOrder($order);
        exit;
    }

    // public function prepareOrder(array $order): array
    // {
    //     if(!empty($order['shipping']['postcode'])){
    //         $order['shipping']['postcode'] 
    //             = substr(valid::onlyNumbers($order['shipping']['postcode']));
    //     }
    //     if(!empty($order['payment']['postcode'])){
    //         $order['payment']['postcode'] 
    //         = substr(valid::onlyNumbers($order['payment']['postcode']));
    //     }

    //     if(!empty($order['shipping']['custom_field'][Opencart::addressComplement()])){
    //         $order['shipping']['custom_field'][Opencart::addressComplement()] 
    //             = preg_replace('/[^a-zA-Z0-9_ \-\.\/]/s','', $this->cleanString(substr($order['shipping']['custom_field'][Opencart::addressComplement()] ,0,19)));
    //     }

    //     if(!empty($order['payment']['custom_field'][Opencart::addressComplement()])){
    //         $order['payment']['custom_field'][Opencart::addressComplement()] 
    //             = preg_replace('/[^a-zA-Z0-9_ \-\.\/]/s','', $this->cleanString(substr($order['payment']['custom_field'][Opencart::addressComplement()] ,0,19)));
    //     }

    //     if(!empty($order['shipping']['custom_field'][Opencart::addressNumber()])){
    //         $order['shipping']['custom_field'][Opencart::addressNumber()] 
    //             = preg_replace('/[^0-9]/s','', $this->cleanString(substr($order['shipping']['custom_field'][Opencart::addressNumber()] ,0,6)));
    //     } else {
    //         $order['shipping']['custom_field'][Opencart::addressNumber()] = 0;
    //     }

    //     if(!empty($order['payment']['custom_field'][Opencart::addressNumber()])){
    //         $order['payment']['custom_field'][Opencart::addressNumber()] 
    //             = preg_replace('/[^0-9]/s','', $this->cleanString(substr($order['payment']['custom_field'][Opencart::addressNumber()] ,0,6)));
    //     } else {
    //         $order['payment']['custom_field'][Opencart::addressNumber()] = 0;
    //     }

    //     if(!empty($order['shipping']['address_1'])){
    //         $order['shipping']['address_1'] 
    //             = preg_replace('/[^a-zA-Z0-9_ \-\.\/]/s','', $this->cleanString(substr($order['shipping']['address_1'],0,20)));
    //     }
    //     if(!empty($order['payment']['address_1'])){
    //         $order['payment']['address_1'] 
    //             = preg_replace('/[^a-zA-Z0-9_ \-\.\/]/s','', $this->cleanString(substr($order['payment']['address_1'],0,20)));
    //     }

    //     if(!empty($order['shipping']['address_2'])){
    //         $order['shipping']['address_2'] 
    //             = preg_replace('/[^a-zA-Z0-9_ \-\.\/]/s','', $this->cleanString(substr($order['shipping']['address_2'],0,20)));
    //     }
    //     if(!empty($order['payment']['address_2'])){
    //         $order['payment']['address_2'] 
    //             = preg_replace('/[^a-zA-Z0-9_ \-\.\/]/s','', $this->cleanString(substr($order['payment']['address_2'],0,20)));
    //     }

    //     if(!empty($order['shipping']['city'])){
    //         $order['shipping']['city'] 
    //             = preg_replace('/[^a-zA-Z0-9_ \-\.\/]/s','', $this->cleanString(substr($order['shipping']['city'],0,20)));
    //     }
    //     if(!empty($order['payment']['city'])){
    //         $order['payment']['city'] 
    //             = preg_replace('/[^a-zA-Z0-9_ \-\.\/]/s','', $this->cleanString(substr($order['payment']['city'],0,20)));
    //     }

    //     if(!empty($order['shipping']['company'])){
    //         $order['shipping']['company'] 
    //             = preg_replace('/[^a-zA-Z0-9_ \-\.\/]/s','', $this->cleanString(substr($order['shipping']['company'],0,20)));
    //     }
    //     if(!empty($order['payment']['company'])){
    //         $order['payment']['company'] 
    //             = preg_replace('/[^a-zA-Z0-9_ \-\.\/]/s','', $this->cleanString(substr($order['payment']['company'],0,20)));
    //     }

    //     if(!empty($order['shipping']['firstname'])){
    //         $order['shipping']['firstname'] 
    //             = preg_replace('/[^a-zA-Z0-9_ \-\.\/]/s','', $this->cleanString(substr($order['shipping']['firstname'],0,20)));
    //     }
    //     if(!empty($order['payment']['firstname'])){
    //         $order['payment']['firstname'] 
    //             = preg_replace('/[^a-zA-Z0-9_ \-\.\/]/s','', $this->cleanString(substr($order['payment']['firstname'],0,20)));
    //     }

    //     if(!empty($order['shipping']['lastname'])){
    //         $order['shipping']['lastname'] 
    //             = preg_replace('/[^a-zA-Z0-9_ \-\.\/]/s','', $this->cleanString(substr($order['shipping']['lastname'],0,20)));
    //     }
    //     if(!empty($order['payment']['lastname'])){
    //         $order['payment']['lastname'] 
    //             = preg_replace('/[^a-zA-Z0-9_ \-\.\/]/s','', $this->cleanString(substr($order['payment']['lastname'],0,20)));
    //     }

    //     if(!empty($order['firstname'])){
    //         $order['firstname'] 
    //             = preg_replace('/[^a-zA-Z0-9_ \-\.\/]/s','', $this->cleanString(substr($order['firstname'],0,20)));
    //     }

    //     if(!empty($order['lastname'])){
    //         $order['lastname'] 
    //             = preg_replace('/[^a-zA-Z0-9_ \-\.\/]/s','', $this->cleanString(substr($order['lastname'],0,20)));
    //     }


    //    return $order;
    // }

    public function orderHistory(string|int $order_id, array $data)
    {
        $params = [
            0 => [
                'order_id' => $order_id,
                'order_status_id' => $data['order_status_id'],
                'comment' => $data['comment'],
                'notify' => $data['notify'],
            ],
        ];

        $this->execute('order/history', 'put', $params);
    }

    public function statuses(?array $queryParams = []): Collection
    {
        $response = $this->execute('order_status', 'get', null, $queryParams);

        return collect($response['records'] ?? []);
    }

    public function products(): Collection
    {
        $response = $this->execute('product');

        return collect($response['records'] ?? []);
    }

    public function searchStatuses(?string $key = ''): array | int | null
    {
        if ($key) {
            return (int) $this->search_statuses[$key] ?? null;
        }

        return $this->search_statuses;
    }

    public function updateStatuses(?string $key = ''): array | int | null
    {
        if ($key) {
            return (int) $this->update_statuses[$key] ?? null;
        }

        return $this->update_statuses;
    }

    public function cnpj(): array|string
    {
        return $this->cnpj;
    }

    public function cpf(): array|string
    {
        return $this->cpf;
    }

    public function stateRegistration(): array|string
    {
        return $this->state_registration;
    }

    public function addressNumber(): array|string
    {
        return $this->address_number;
    }

    public function addressComplement(): array|string
    {
        return $this->address_complement;
    }

    public function birthdate(): array|string
    {
        return $this->birthdate;
    }

    public function branch(): array|string
    {
        return $this->branch;
    }

    public function registration(): array|string
    {
        return $this->registration;
    }

    public function cellphone(): array|string
    {
        return $this->cellphone;
    }

    public function getCustomField(array $custom_fields, array|string $keys ): string
    {
        if(is_array($keys)){
            foreach($keys as $key){
                if(!empty($custom_fields[$key])){
                    return $custom_fields[$key];
                }
            }
             
        }else{
            return $custom_fields[$keys]??'';
        }

        return '';
    }


    private function action(string $action, ?array $queryParams = []): string
    {
        $url = "{$this->api_url}/{$action}";

        if ($queryParams) {
            $url .= '?' . http_build_query($queryParams);
        }

        return $url;
    }

    private function accessToken()
    {
        $response = Http::withHeaders($this->headers())->post($this->action('access_token'));

        $this->validate($response);

        $this->access_token = $response->object()->access_token;
    }

    private function validate(Response $response)
    {
        if ($response->serverError()) {
            throw new \Exception($this->errorMessage($response));
        }
    }

    private function errorMessage(Response $response): string
    {
        $errors = $response->object()?->errors ?? [];

        $array_errors = [];

        foreach ($errors as $error) {
            $array_errors[] = $error->message;
        }

        return implode(' ', $array_errors);
    }
    
    private function headers(): array
    {
        return [
            'Authorization' => $this->basicAuth(),
        ];
    }

    private function bearer(): string
    {
        return "Bearer {$this->access_token}";
    }

    private function execute(string $action, string $method = 'get', ?array $params = [], ?array $queryParams = [])
    {
        $headers = [
            'Authorization' => $this->bearer(),
        ];

        $url = $this->action($action, $queryParams);

        $response = Http::withHeaders($headers)->$method($url, $params);

        $this->log($url, $response, $params);

        $this->validate($response);

        return $response->json();
    }

    private function log(string $url, Response $response, ?array $params = [])
    {
        $log_data = [
            'url' => $url,
            'body' => $response->json(),
            'params' => $params,
        ];

        // $stringfied_data = json_encode($log_data);

        // $key = md5($stringfied_data . time());

        // Storage::put("api-calls/opencart/{$key}.txt", $stringfied_data);
    }

    private function basicAuth(): string
    {
        return 'Basic ' . base64_encode("{$this->consumer_key}:{$this->consumer_secret}");
    }

    private function validateSettings()
    {
        $is_settings_valid = config('connector.consumer_key') && config('connector.consumer_secret') && config('connector.opencart_url');

        throw_unless($is_settings_valid, new \Exception('As configurações estão inválidas'));
    }

    public function cleanString($text) {
        $utf8 = array(
            '/[áàâãªä]/u'   =>   'a',
            '/[ÁÀÂÃÄ]/u'    =>   'A',
            '/[ÍÌÎÏ]/u'     =>   'I',
            '/[íìîï]/u'     =>   'i',
            '/[éèêë]/u'     =>   'e',
            '/[ÉÈÊË]/u'     =>   'E',
            '/[óòôõºö]/u'   =>   'o',
            '/[ÓÒÔÕÖ]/u'    =>   'O',
            '/[úùûü]/u'     =>   'u',
            '/[ÚÙÛÜ]/u'     =>   'U',
            '/ç/'           =>   'c',
            '/Ç/'           =>   'C',
            '/ñ/'           =>   'n',
            '/Ñ/'           =>   'N',
            '/–/'           =>   '-', // UTF-8 hyphen to "normal" hyphen
            '/[’‘‹›‚]/u'    =>   ' ', // Literally a single quote
            '/[“”«»„]/u'    =>   ' ', // Double quote
            '/ /'           =>   ' ', // nonbreaking space (equiv. to 0x160)
        );
        return preg_replace(array_keys($utf8), array_values($utf8), $text);
    }
     

    private function validateAplic($fields, $field_name)
    {
        $error = [];
        if(empty($fields[$field_name])){
            $error[] = sprintf($this->text_not_blank, $field_name );
        }

        if(!empty($fields[$field_name]) && $fields[$field_name] != 'ECMB'){
            $error[] = "{$field_name} - Valor incorreto. Valor esperado \"ECMB\", Valor Obtido:: \"{$fields[$field_name]}\"";
        }

        if(!empty($fields[$field_name]) && strlen($fields[$field_name]) > 6){
            $error[] = sprintf($this->text_max_char, $field_name, '6',  $fields[$field_name]);
        }

        return $error;
    }
    
    private function logItemsQtDivergentes($log)
    {
        $log = date("Y-m-d h:i:sa")." - ".$log ;
        $log = $log . PHP_EOL;
        File::append(
            storage_path('/logs/error_items_qt_divergentes.log'), $log
       );
    }
    
    private function logValidateData($log)
    {
        var_dump($log);
        $log = date("Y-m-d h:i:sa")." - ".$log ;
        $log = $log . PHP_EOL;
        File::append(
            storage_path('/logs/error_validate_data.log'), $log
       );
    }


}