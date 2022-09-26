<?php

return [
    'opencart_url' => env('OPENCART_URL', null),  
    'consumer_key' => env('OPENCART_CONSUMER_KEY', null),
    'consumer_secret' => env('OPENCART_CONSUMER_SECRET', null),
    'search_status' => [
        'new_order' => 2,
        'after_dispatch_order' => 5,
        'invoiced_order' => 6,
        'in_dispatchment_order' => 4,
    ],
    'update_status' => [
        'integrated_sexp_order' => 4,
        'dispatched_order' => 5,
        'in_invoicement_order' => 6,
        'finished_order' => 7,
    ],
    'custom_fields' => [
        'cnpj' => 'CNPJ',
        'cpf' => 'CPF',
        'state_registration' => 'IE',
        'address_number' => '7',
        'address_complement' => '6',
        'neighborhood' => '8'
    ],
    'application_code' => 'ECMB',
    'ibge_country' => '1058',
    'main_address' => [
        'address' => 'RENE BITENCOURT',
        'number' => '25',
        'neighborhood' => 'SB',
        'postcode' => '22558441',
        'city' => 'Rio de Janeiro',
        'state_code' => 'RJ',
        'country' => 'BR',
    ],
];