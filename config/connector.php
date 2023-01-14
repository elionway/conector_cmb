<?php

return [
    'opencart_url' => env('OPENCART_URL', null),  
    'consumer_key' => env('OPENCART_CONSUMER_KEY', null),
    'consumer_secret' => env('OPENCART_CONSUMER_SECRET', null),
    'search_status' => [
        'new_order'                   => 3,  //  1-Pagamento Aprovado >> 4-Enviado ao Sexp
        'in_dispatchment_order'       => 4,  //  4-Enviado ao Sexp  >> 5-Recebido no cobre do SEXP
        'after_dispatch_order'        => 5,  //  5-Recebido no cobre do SEXP >> 6-Enviado a DMZ do ERP
        'invoiced_order'              => 6,  //  6-Enviado a DMZ do ERP >> 20-Aguardando Coleta            
        'sent_invoiced_to_sexp_order' => 20, // 20-Aguardando Coleta >> 7-Enviado
        'with_error_item'             => 50// 22, // 22-Com erro de sincronização de itens, somente usar em ajustes manuais no script
    ],
    'update_status' => [
        'integrated_sexp_order'       => 4,  // Enviado ao Sexp, aguardando rastreio e cofre
        'dispatched_order'            => 5,  // Recebido no cobre do SEXP
        'in_invoicement_order'        => 6,  // Enviado a DMZ do ERP
        'sent_invoiced_to_sexp_order' => 20, // Aguardando Coleta
        'finished_order'              => 7,  // Enviado
        'with_error_item_data'        => 25, // pedidos com erro na validação de dados

        'with_error_item_qt'          => 23, // Com erro de QUANTIDADE NA REsincronização de itens
        'with_error_item_ok'          => 24,   
    ],
    'custom_fields' => [
        'cnpj' => ['CNPJ','2'],
        'cpf' => ['CPF','1'],   
        'state_registration' => ['IE','4'],
        'address_number' => ['number','7'],
        'address_complement' => ['complement', '6'],
        'birthdate' => ['data_nasc','3'],
        'branch' => ['ramal','9'],
        'registration' => ['matricula','8'],
        'cellphone' => ['celular','10'],
    ],
    'application_code' => 'ECMB',
    'ibge_country' => '1058',
    'teste' => 'teste retornado',
    'main_address' => [
        'address' => 'RENE BITTENCOURT',
        'number' => '371',
        'neighborhood' => 'Distrito Industrical de Santa Cruz',
        'postcode' => '23565200',
        'city' => 'Rio de Janeiro',
        'state_code' => 'RJ',
        'country' => 'BR',
    ],
    'payment_codes' => [
        'cielo_api_boleto' => '010',
        'cielo_api_debito' => '071',
        'cielo_api_credito' => [
            '1' => '072',
            '2' => '073',
            '3' => '074',
            '4' => '075',
            '5' => '076',
            '6' => '077',
        ],
    ],
    'payment_methods' => [
        'cielo_api_boleto' => 1,
        'cielo_api_debito' => 3,
        'cielo_api_credito' => 4,
    ],
    'erp_tes' => ['654','890','597','504','598','50A', '59R'], //codigo de tipo de estado para o produto
    'erp_uf' => ['AC','AL','AP','AM','BA','CE','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO','DF', 'EX'], //UF permitidos
    'erp_ddd' => ['61','62','64','65','66','67','82','71','73','74','75','77','85','88','98','99','83','81','87','86','89','84','79','68','96','92','97','91','93','94','69','95','63','27','28','31','32','33','34','35','37','38','21','22','24','11','12','13','14','15','16','17','18','19','41','42','43','44','45','46','51','53','54','55','47','48','49'], // DDD permitidos
];