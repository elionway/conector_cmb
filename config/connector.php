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
        'cnpj' => 'cnpj',
        'cpf' => 'cpf',
    ],
];