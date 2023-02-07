<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class Valid extends Model
{
    use HasFactory;

    private ?array $payment_methods = [];

    private ?array $payment_codes = [];

    public function __construct()
    {
        $this->payment_methods =  config('connector.payment_methods');

        $this->payment_codes = config('connector.payment_codes');

    }

    protected static function onlyNumbers($value){
        return  preg_replace("/[^0-9]/", "", $value);
    }
    protected static function consultCep($cep)
    {
       
        $postcode = self::onlyNumbers($cep);
        $data = Cache::get($postcode);
        $consult = [];

        //var_dump($data);

       // dd($data);
        if(!empty($postcode) && empty($data['cep'])){  
            
            if(!empty(self::consultCepBrasilApi($postcode)['cep'])){
                $consult = self::consultCepBrasilApi($postcode);
                $data = [
                    "cep"        => self::onlyNumbers($consult['cep']??''),
                    "logradouro" => self::cleanString($consult['street']??''),
                    "bairro"     => self::cleanString($consult['neighborhood']??''),
                    "cidade"     => self::cleanString($consult['city']??''),
                    'uf'         => self::cleanString($consult['state']??''),                   
                ];
            }elseif(!empty(self::consultCepOwcep($postcode)['cep'])){
                $consult = self::consultCepOwcep($postcode);
                $data = [
                    "cep"        => self::onlyNumbers($consult['cep']??''),
                    "logradouro" => self::cleanString($consult['logradouro']??''),
                    "bairro"     => self::cleanString($consult['bairro']??''),
                    "cidade"     => self::cleanString($consult['cidade']??''),
                    'uf'         => self::cleanString($consult['uf']??''),
                ];
            }elseif(!empty(self::consultCepViacep($postcode)['cep'])){
                $consult = self::consultCepViacep($postcode);
                $data = [
                    "cep"        => self::onlyNumbers($consult['cep']??''),
                    "logradouro" => self::cleanString($consult['logradouro']??''),
                    "bairro"     => self::cleanString($consult['bairro']??''),
                    "cidade"     => self::cleanString($consult['cidade']??''),
                    'uf'         => self::cleanString($consult['uf']??''),
                ];
            }

            if(!empty($data['cep'])){
                $duration = 60 * 60 * 24 * 7;
                Cache::set($postcode, $data, $duration);
            }else{
                Cache::forget($postcode);
            }
            
        }

        return $data ;
    }
    

    protected static function consultCepBrasilApi($cep){
        try {
            return json_decode(Http::get("https://brasilapi.com.br/api/cep/v2/{$cep}"), true);
        } catch (Exception $e) {
            return [];
        }
        
    }

   protected static function getIbgeCodigoMunicipioByOrderInfo($order)
    {

        $postcode = $order['shipping']['postcode']??'';
        $municipio = strtolower(valid::cleanString($order['shipping']['city']??''));
        $code_uf = strtolower(valid::cleanString($order['shipping']['zone_code']??''));

        $code = self::consultIbgeMunicipioCodigoBrasilApi( $code_uf, $municipio);
        
        if(empty($code)){            
            if(!empty(valid::consultCepOwcep($postcode)['ibge'])){
                $code =valid::consultCepOwcep($postcode)['ibge'];
            }elseif(!empty(valid::consultCepViacep(($postcode)['ibge']))){
                $code =valid::consultCepViacep($postcode['ibge']);
            }
        }

        return substr($code??'', -5) ;
    }

    protected static  function consultIbgeMunicipioCodigoBrasilApi($siglaUF, $city_parm){

        $cities = self::getCitiesFromBrasilApiByUF($siglaUF);       
        
        $city = $cities[$city_parm]??'';

        return $city['codigo_ibge']??'';
    }

    protected static function getCitiesFromBrasilApiByUF($siglaUF){

        $cities = Cache::get($siglaUF);

        if(empty($cities)){

            try {
                $cities_url = json_decode(Http::get("https://brasilapi.com.br/api/ibge/municipios/v1/{$siglaUF}?providers=dados-abertos-br,gov,wikipedia"), true);;
            
                $cities = [];
                foreach($cities_url as $city_url){
                    if(!empty($city_url['nome']) && !empty($city_url["codigo_ibge"])){
                        $city = strtolower(valid::cleanString($city_url['nome']));
                        $cities[$city]  = [
                            'nome' => strtolower(valid::cleanString($city_url['nome'])),
                            'codigo_ibge' => $city_url["codigo_ibge"]
                        ];
                    }                    
                }

                $duration = 60 * 60 * 24 * 7;
                Cache::set($siglaUF, $cities, $duration);
            } catch (Exception $e) {
               return [];
            }

        }
       
        return $cities;
    }


    protected static function consultCepOwcep($postcode)
    {
        return  Http::get("https://clubedamedalha.com.br/index.php?route=extension/module/ow_cep/getcep&cep={$postcode}");
    }

    protected static function consultCepViacep($postcode)
    {     
        return  Http::get("https://viacep.com.br/ws/{$postcode}/json/");
    }


    protected static function verifica_cpf_cnpj ($document) {
		// Verifica CPF
		if ( strlen( $document ) === 11 ) {
			return 'CPF';
		} 
		// Verifica CNPJ
		elseif ( strlen( $document ) === 14 ) {
			return 'CNPJ';
		} 
		// Não retorna nada
		else {
			return false;
		}
	}
    
	/**
	 * Verifica se todos os números são iguais
	 * 	 * 
	 * @access protected
	 * @return bool true para todos iguais, false para números que podem ser válidos
	 */
    protected static function verifica_igualdade($document) {
        // Todos os caracteres em um array
        $caracteres = str_split($document );
        
        // Considera que todos os números são iguais
        $todos_iguais = true;
        
        // Primeiro caractere
        $last_val = $caracteres[0];
        
        // Verifica todos os caracteres para detectar diferença
        foreach( $caracteres as $val ) {
            
            // Se o último valor for diferente do anterior, já temos
            // um número diferente no CPF ou CNPJ
            if ( $last_val != $val ) {
               $todos_iguais = false; 
            }
            
            // Grava o último número checado
            $last_val = $val;
        }
        
        // Retorna true para todos os números iguais
        // ou falso para todos os números diferentes
        return $todos_iguais;
    }

    protected static function ddd($ddd)
    {     
        if(in_array($ddd, config('connector.erp_ddd'))){
            return true;
        }
        return false;
    }

    protected static function ufSigla($ufSigla)
    {     
        if(in_array($ufSigla, config('connector.erp_uf'))){
            return true;
        }
        return false;
    }

    protected static function inTes($value)
    {     
        if(in_array($value, config('connector.erp_tes'))){
            return true;
        }
        return false;
    }

    protected static function validateExistPaymentCodes($code)
    {     
    
        $payment_codes = config('connector.payment_codes');
        $codes = [];
        foreach($payment_codes as $payment_code){
            
            if(is_array($payment_code)){
                $codes = array_merge($codes ,$payment_code);
            }else{
                $codes[] = $payment_code;
            }
        }
        
        if(in_array($code, $codes)){
            return true;
        }
        return false;
    }   

    	
	protected static function calc_digitos_posicoes( $digitos, $posicoes = 10, $soma_digitos = 0 ) {
		// Faz a soma dos dígitos com a posição
		// Ex. para 10 posições:
		//   0    2    5    4    6    2    8    8   4
		// x10   x9   x8   x7   x6   x5   x4   x3  x2
		//   0 + 18 + 40 + 28 + 36 + 10 + 32 + 24 + 8 = 196
		for ( $i = 0; $i < strlen( $digitos ); $i++  ) {
			// Preenche a soma com o dígito vezes a posição
			$soma_digitos = $soma_digitos + ( $digitos[$i] * $posicoes );

			// Subtrai 1 da posição
			$posicoes--;

			// Parte específica para CNPJ
			// Ex.: 5-4-3-2-9-8-7-6-5-4-3-2
			if ( $posicoes < 2 ) {
				// Retorno a posição para 9
				$posicoes = 9;
			}
		}

		// Captura o resto da divisão entre $soma_digitos dividido por 11
		// Ex.: 196 % 11 = 9
		$soma_digitos = $soma_digitos % 11;

		// Verifica se $soma_digitos é menor que 2
		if ( $soma_digitos < 2 ) {
			// $soma_digitos agora será zero
			$soma_digitos = 0;
		} else {
			// Se for maior que 2, o resultado é 11 menos $soma_digitos
			// Ex.: 11 - 9 = 2
			// Nosso dígito procurado é 2
			$soma_digitos = 11 - $soma_digitos;
		}

		// Concatena mais um dígito aos primeiro nove dígitos
		// Ex.: 025462884 + 2 = 0254628842
		$cpf = $digitos . $soma_digitos;

		// Retorna
		return $cpf;
	}

	/**
	 * Valida CPF
	 *
	 * @author                Luiz Otávio Miranda <contato@todoespacoonline.com/w>
	 * @access protected
	 * @param  string    $cpf O CPF com ou sem pontos e traço
	 * @return bool           True para CPF correto - False para CPF incorreto
	 */
	protected static function valida_cpf($document){
		
		$document = self::sanitizeString($document);
		// Captura os 9 primeiros dígitos do CPF
		// Ex.: 02546288423 = 025462884
		$digitos = substr($document, 0, 9);

		// Faz o cálculo dos 9 primeiros dígitos do CPF para obter o primeiro dígito
		$novo_cpf = self::calc_digitos_posicoes( $digitos );

		// Faz o cálculo dos 10 dígitos do CPF para obter o último dígito
		$novo_cpf = self::calc_digitos_posicoes( $novo_cpf, 11 );
        
        // Verifica se todos os números são iguais
        if ( self::verifica_igualdade($document) ) {
            return false;
        }

		// Verifica se o novo CPF gerado é idêntico ao CPF enviado
		if ( $novo_cpf === $document ) {
			// CPF válido
			return true;
		} else {
			// CPF inválido
			return false;
		}
	}

	/**
	 * Valida CNPJ
	 *
	 * @author                  Luiz Otávio Miranda <contato@todoespacoonline.com/w>
	 * @access protected
	 * @param  string     $cnpj
	 * @return bool             true para CNPJ correto
	 */
	protected static function valida_cnpj ($document) {
		
		$document = self::sanitizeString($document);
		// O valor original
		$cnpj_original = $document;

		// Captura os primeiros 12 números do CNPJ
		$primeiros_numeros_cnpj = substr( $document, 0, 12 );

		// Faz o primeiro cálculo
		$primeiro_calculo = self::calc_digitos_posicoes( $primeiros_numeros_cnpj, 5 );

		// O segundo cálculo é a mesma coisa do primeiro, porém, começa na posição 6
		$segundo_calculo = self::calc_digitos_posicoes( $primeiro_calculo, 6 );

		// Concatena o segundo dígito ao CNPJ
		$cnpj = $segundo_calculo;
        
        // Verifica se todos os números são iguais
        if ( self::verifica_igualdade($document) ) {
            return false;
        }

		// Verifica se o CNPJ gerado é idêntico ao enviado
		if ( $cnpj === $cnpj_original ) {
			return true;
		}
	}

	/**
	 * Valida
	 * 
	 * Valida o CPF ou CNPJ
	 * 
	 * @access public
	 * @return bool      True para válido, false para inválido
	 */
	public static function validateDocument($document) {
		
        $document = self::sanitizeString($document);
		// Valida CPF
		if ( self::verifica_cpf_cnpj($document) === 'CPF' ) {
			// Retorna true para cpf válido
			return self::valida_cpf($document);
		} 
		// Valida CNPJ
		elseif ( self::verifica_cpf_cnpj($document) === 'CNPJ' ) {
			// Retorna true para CNPJ válido
			return self::valida_cnpj($document);
		} 
		// Não retorna nada
		else {
			return false;
		}
	}

    public static function sanitizeString($document)
    {
        return preg_replace("/[^0-9]/", "", $document); 
    }

    public static function stringErp($string)
    {

        $regex = '/[^A-Za-z0-9\s.\-\/ ]/';

        return preg_match($regex, $string)?true:false;
        
    }

    public static function isOnlyNumber($string)
    {

        $regex = '/[^0-9]/';

        return preg_match($regex, $string)?false:true;
        
    }

    public static function stringEnderecoErp($string)
    {

        $regex = '/[^A-Za-z0-9\s,.\-\/ ]/';

        return preg_match($regex, $string)?true:false;
        
    }

    public static function inscricao_estadual($ie, $estado)
    {

        $estado = strtoupper($estado);

        $pesos = array(
            'p1' => array(
                '1' => '0',
                '2' => '2',
                '3' => '3',
                '4' => '4',
                '5' => '5',
                '6' => '6',
                '7' => '7',
                '8' => '8',
                '9' => '9',
                '10' => '2',
                '11' => '3',
                '12' => '4',
                '13' => '5',
                '14' => '6',
            ),
            'p2' => array(
                '1' => '0',
                '2' => '0',
                '3' => '2',
                '4' => '3',
                '5' => '4',
                '6' => '5',
                '7' => '6',
                '8' => '7',
                '9' => '8',
                '10' => '9',
                '11' => '2',
                '12' => '3',
                '13' => '4',
                '14' => '5',
            ),
            'p3' => array(
                '1' => '2',
                '2' => '0',
                '3' => '3',
                '4' => '4',
                '5' => '5',
                '6' => '6',
                '7' => '7',
                '8' => '8',
                '9' => '9',
                '10' => '2',
                '11' => '3',
                '12' => '4',
                '13' => '5',
                '14' => '6',
            ),
            'p4' => array(
                '1' => '0',
                '2' => '2',
                '3' => '3',
                '4' => '4',
                '5' => '5',
                '6' => '6',
                '7' => '0',
                '8' => '0',
                '9' => '0',
                '10' => '0',
                '11' => '0',
                '12' => '0',
                '13' => '0',
                '14' => '0',
            ),
            'p5' => array(
                '1' => '0',
                '2' => '8',
                '3' => '7',
                '4' => '6',
                '5' => '5',
                '6' => '4',
                '7' => '3',
                '8' => '2',
                '9' => '1',
                '10' => '0',
                '11' => '0',
                '12' => '0',
                '13' => '0',
                '14' => '0',
            ),
            'p6' => array(
                '1' => '0',
                '2' => '2',
                '3' => '3',
                '4' => '4',
                '5' => '5',
                '6' => '6',
                '7' => '7',
                '8' => '0',
                '9' => '0',
                '10' => '8',
                '11' => '9',
                '12' => '0',
                '13' => '0',
                '14' => '0',
            ),
            'p7' => array(
                '1' => '0',
                '2' => '2',
                '3' => '3',
                '4' => '4',
                '5' => '5',
                '6' => '6',
                '7' => '7',
                '8' => '8',
                '9' => '9',
                '10' => '1',
                '11' => '2',
                '12' => '3',
                '13' => '4',
                '14' => '5',
            ),
            'p8' => array(
                '1' => '0',
                '2' => '2',
                '3' => '3',
                '4' => '4',
                '5' => '5',
                '6' => '6',
                '7' => '7',
                '8' => '2',
                '9' => '3',
                '10' => '4',
                '11' => '5',
                '12' => '6',
                '13' => '7',
                '14' => '8',
            ),
            'p9' => array(
                '1' => '0',
                '2' => '0',
                '3' => '2',
                '4' => '3',
                '5' => '4',
                '6' => '5',
                '7' => '6',
                '8' => '7',
                '9' => '2',
                '10' => '3',
                '11' => '4',
                '12' => '5',
                '13' => '6',
                '14' => '7',
            ),
            'p10' => array(
                '1' => '0',
                '2' => '0',
                '3' => '2',
                '4' => '1',
                '5' => '2',
                '6' => '1',
                '7' => '2',
                '8' => '1',
                '9' => '2',
                '10' => '1',
                '11' => '1',
                '12' => '2',
                '13' => '1',
                '14' => '0',
            ),
            'p11' => array(
                '1' => '0',
                '2' => '2',
                '3' => '3',
                '4' => '4',
                '5' => '5',
                '6' => '6',
                '7' => '7',
                '8' => '8',
                '9' => '9',
                '10' => '10',
                '11' => '11',
                '12' => '2',
                '13' => '3',
                '14' => '0',
            ),
            'p12' => array(
                '1' => '0',
                '2' => '0',
                '3' => '0',
                '4' => '0',
                '5' => '10',
                '6' => '8',
                '7' => '7',
                '8' => '6',
                '9' => '5',
                '10' => '4',
                '11' => '3',
                '12' => '1',
                '13' => '0',
                '14' => '0',
            ),
            'p13' => array(
                '1' => '0',
                '2' => '2',
                '3' => '3',
                '4' => '4',
                '5' => '5',
                '6' => '6',
                '7' => '7',
                '8' => '8',
                '9' => '9',
                '10' => '10',
                '11' => '2',
                '12' => '3',
                '13' => '0',
                '14' => '0',
            ),
        );


        $md = '';

        $peso = '';

        $tamanho = 0;

        // Acre
        if ($estado == 'AC')
        {

            $fator = 0;


            /*
             * IEs ap�s 11/99
             */
            if (strlen($ie) == 13)
            {
                $md = array(11, 11);

                $peso = array('p2', 'p1');

                $rot = array(array('E'), array('E'));

                $verificadores = array(12, 13);

                $tamanho = 13;
            }
            /*
             * IEs at� 11/99
             */
            else if (strlen($ie) == 9)
            {
                $md = array(11);

                $peso = array('p1');

                $rot = array(array('E'));

                $verificadores = array(9);

                $tamanho = 9;
            }
            else
            {
                return false;
            }

            if (substr($ie, 0, 2) != '01')
            {
                return false;
            }
        }
        else if ($estado == 'AL')
        {
            $md = array(11);

            $rot = array(array('B', 'D'));

            $fator = 0;

            $peso = array('p1');

            $tamanho = 9;

            $verificadores = array(9);

            if (substr($ie, 0, 2) != '24')
            {
                return false;
            }


            /*
             * Desabilitado pois existem IEs v�lidas que n�o se enqudram nessa regra
             * if (!in_array(substr($ie, 2, 1), array(0, 3, 5, 7, 8)))
              {
              return false;
              } */
        }
        else if ($estado == 'AP')
        {
            $md = array(11);

            $peso = array('p1');

            $tamanho = 9;

            $verificadores = array(9);

            if ((int)$ie <= 30170009)
            {
                $rot = array(array('C', 'E'));

                $fator = 0;
            }
            else if ((int)$ie >= 30170010 AND (int)$ie <= 30190229)
            {    

                $rot = array(array('C', 'E'));

                $fator = 1;
            }
            else
            {  
                $rot = array(array('E'));

                $fator = 0;
            }

            if (substr($ie, 0, 2) != '03')
            {
                return false;
            }
        }
        else if ($estado == 'AM')
        {
            $md = array(11);

            $peso = array('p1');

            $tamanho = 9;

            $verificadores = array(9);

            $rot = array(array('E'));

            $fator = 0;

            if (substr($ie, 0, 2) != '04' AND substr($ie, 0, 2) != '07')
            {
                return false;
            }
        }
        else if ($estado == 'BA')
        {

            $peso = array('p2', 'p3');

            $tamanho = 8;

            $verificadores = array(8, 7); // O 2� d�gito vem primeiro

            $rot = array(array('E'), array('E'));

            $fator = 0;

            if (!in_array(substr($ie, 0, 1), array(6, 7, 9)))
            {
                $md = array(10, 10);
            }
            else
            {
                $md = array(11, 11);
            }
        }
        else if ($estado == 'CE')
        {

            $peso = array('p1');

            $tamanho = 9;

            $verificadores = array(9);

            $rot = array(array('E'));

            $fator = 0;

            $md = array(11);

            if (substr($ie, 0, 1) != '0')
            {
                return false;
            }
        }
        else if ($estado == 'DF')
        {           
            
            $peso = array('p2', 'p1');

            $tamanho = 13;

            $verificadores = array(12, 13);

            $rot = array(array('E'), array('E'));

            $fator = 0;

            $md = array(11, 11);

            if (substr($ie, 0, 3) != '073' AND substr($ie, 0, 3) != '074' AND substr($ie, 0, 3) != '075')
            {
                return false;
            }
        }
        else if ($estado == 'ES')
        {

            $peso = array('p1');

            $tamanho = 9;

            $verificadores = array(9);

            $rot = array(array('E'));

            $fator = 0;

            $md = array(11);

            if (substr($ie, 0, 2) != '00' AND substr($ie, 0, 2) != '08')
            {
                return false;
            }
        }
        else if ($estado == 'GO')
        {

            $peso = array('p1');

            $tamanho = 9;

            $verificadores = array(9);

            $rot = array(array('E'));

            $md = array(11);

            if ($ie >= 101031050 AND $ie <= 101199979)
            {

                $fator = 0;
            }
            else
            {
                $fator = 1;
            }

            if (substr($ie, 0, 2) != '10' AND substr($ie, 0, 2) != '11' AND substr($ie, 0, 2) != '15')
            {
                return false;
            }
        }
        else if ($estado == 'MA')
        {

            $peso = array('p1');

            $tamanho = 9;

            $verificadores = array(9);

            $rot = array(array('E'));

            $md = array(11);

            $fator = 0;

            if (substr($ie, 0, 2) != '12')
            {
                return false;
            }
        }
        else if ($estado == 'MT')
        {

            $peso = array('p1');

            /*
             * No site da Fazenda diz que � 11, mas as IEs todas do MT que vi � 9
             */
            $tamanho = 9;

            $verificadores = array(9);

            $rot = array(array('E'));

            $md = array(11);

            $fator = 0;
        }
        else if ($estado == 'MS')
        {

            $peso = array('p1');

            $tamanho = 9;

            $verificadores = array(9);

            $rot = array(array('E'));

            $md = array(11);

            $fator = 0;

            if (substr($ie, 0, 2) != '28')
            {
                return false;
            }
        }
        else if ($estado == 'MG')
        {

            $peso = array('p10', 'p11');

            $tamanho = 13;

            $verificadores = array(12, 13);

            $rot = array(array('A', 'E'), array('E'));

            $md = array(10, 11);

            $fator = 0;
        }
        else if ($estado == 'PA')
        {

            $peso = array('p1');

            $tamanho = 9;

            $verificadores = array(9);

            $rot = array(array('E'));

            $md = array(11);

            $fator = 0;

            if (substr($ie, 0, 2) != '15')
            {
                return false;
            }
        }
        else if ($estado == 'PB')
        {

            $peso = array('p1');

            $tamanho = 9;

            $verificadores = array(9);

            $rot = array(array('E'));

            $md = array(11);

            $fator = 0;

            if (substr($ie, 0, 2) != '16')
            {
                return false;
            }
        }
        else if ($estado == 'PR')
        {

            $peso = array('p9', 'p8');

            $tamanho = 10;

            $verificadores = array(9, 10);

            $rot = array(array('E'), array('E'));

            $md = array(11, 11);

            $fator = 0;
        }
        else if ($estado == 'PE')
        {

            $peso = array('p7');

            $tamanho = 14;

            $verificadores = array(14);

            $rot = array(array('E'));

            $md = array(11);

            $fator = 1;

            if (substr($ie, 0, 2) != '18')
            {
                return false;
            }
        }
        else if ($estado == 'PI')
        {

            $peso = array('p1');

            $tamanho = 9;

            $verificadores = array(9);

            $rot = array(array('E'));

            $md = array(11);

            $fator = 0;

            if (substr($ie, 0, 2) != '19')
            {
                return false;
            }
        }
        else if ($estado == 'RJ')
        {

            $peso = array('p8');

            $tamanho = 8;

            $verificadores = array(8);

            $rot = array(array('E'));

            $md = array(11);

            $fator = 0;

            if (!in_array(substr($ie, 0, 1), array(1, 7, 8, 9)))
            {
                return false;
            }
        }
        else if ($estado == 'RN')
        {

            $peso = array('p1');

            $tamanho = 9;

            $verificadores = array(9);

            $rot = array(array('B', 'D'));

            $md = array(11);

            $fator = 0;

            if (substr($ie, 0, 2) != '20')
            {
                return false;
            }
        }
        else if ($estado == 'RS')
        {

            $peso = array('p1');

            $tamanho = 10;

            $verificadores = array(10);

            $rot = array(array('E'));

            $md = array(11);

            $fator = 0;

            if (substr($ie, 0, 1) > 4)
            {
                return false;
            }
        }
        else if ($estado == 'RO')
        {

            $rot = array(array('E'));

            $md = array(11);

            /*
             * IEs at� 07/2000
             */
            if (strlen($ie) == 9)
            {
                $tamanho = 9;

                $fator = 1;

                $verificadores = array(9);

                $peso = array('p4');

                if (substr($ie, 0, 1) == 0)
                {
                    return false;
                }
            }
            /*
             * IEs ap�s 08/2000
             */
            else if (strlen($ie) == 14)
            {
                $tamanho = 14;

                $fator = 0;

                $verificadores = array(14);

                $peso = array('p1');
            }
        }
        else if ($estado == 'RR')
        {

            $peso = array('p5');

            $tamanho = 9;

            $verificadores = array(9);

            $rot = array(array('D'));

            $md = array(9);

            $fator = 0;

            if (substr($ie, 0, 2) != '24')
            {
                return false;
            }
        }
        else if ($estado == 'SC')
        {

            $peso = array('p1');

            $tamanho = 9;

            $verificadores = array(9);

            $rot = array(array('E'));

            $md = array(11);

            $fator = 0;
        }
        else if ($estado == 'SP')
        {
            /*
             * IE com�cio e ind�stria
             */
            if (substr($ie, 0, 1) != 'P')
            {
                $tamanho = 12;

                $verificadores = array(9, 12);

                $peso = array('p12', 'p13');

                $md = array(11, 11);

                $rot = array(array('D'), array('D'));
            }
            /*
             * IE rural
             */
            else
            {
                $tamanho = 13;

                $verificadores = array(10);

                $peso = array('p12');

                $md = array(11);

                $rot = array(array('D'));
            }

            $fator = 0;
        }
        else if ($estado == 'SE')
        {

            $peso = array('p1');

            $tamanho = 9;

            $verificadores = array(9);

            $rot = array(array('E'));

            $md = array(11);

            $fator = 0;
        }
        else if ($estado == 'TO')
        {

            $peso = array('p6');

            $tamanho = 11;

            $verificadores = array(11);

            $rot = array(array('E'));

            $md = array(11);

            $fator = 0;

            if (substr($ie, 0, 2) != '29')
            {
                return false;
            }

            if (!in_array(substr($ie, 3, 1), array(1, 2, 3, 9)))
            {
                return false;
            }
        }

        if ($tamanho <> strlen($ie))
        {
            return false;
        }

        $j = 0;
    
        foreach ($verificadores as $verificador)
        {            
            $soma = 0;

            for ($i = 0; $i < ($verificador - 1); $i++)
            {
                $digito = substr($ie, $i, 1);

                $indice_peso = strlen($ie) - $i;

                $valor_peso = $pesos[$peso[$j]][$indice_peso];

                $mi = $digito * $valor_peso;

                if (in_array('A', $rot[$j]))
                {
                    $mi = $mi + floor($mi / 10);
                }

                $soma += $mi;
            }

            /*
             * Inclui o �ltimo d�gito na soma
             * BA exce��o dig�to 2 ven antes
             */
            if ($estado == 'BA' AND $verificador == '7')
            {
                $soma += substr($ie, 7, 1) * 2;
            }

            if (in_array('B', $rot[$j]))
            {
                $soma = $soma * 10;
            }

            if (in_array('C', $rot[$j]))
            {
                $soma = $soma + 5 + (4 * $fator);
            }

            if (in_array('D', $rot[$j]))
            {
                $digito_verificador = $soma % $md[$j];
            }
            else if (in_array('E', $rot[$j]))
            {
                $digito_verificador = $md[$j] - ($soma % $md[$j]);
            }

            if ($digito_verificador == 10)
            {
                $digito_verificador = 0;
            }
            else if ($digito_verificador == 11)
            {
                $digito_verificador = $fator;
            }

            if ($digito_verificador != substr($ie, $verificador - 1, 1))
            {
                return false;
            }

            $j++;
        }
        
        return true;
    }

    protected static function cleanNumber($text) {
       return preg_replace('/[^0-9]/', '', $text);
    }

    public function validateEmail($email)
    {
        $regex = '/^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,3})$/'; 
        return (preg_match($regex, $email))?true:false;       
    }

    protected static function cleanString($text) {
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
            '/[-_]/'             =>   ' ', //replace hyphen and underscore
            '/[^0-9A-Za-z\s]/'   =>   '', //replace another special chars
            
        );
        return trim(preg_replace(array_keys($utf8), array_values($utf8), $text));
    }
}