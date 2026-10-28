<?php

return [
    'enabled' => (bool) env('CONNECTOR_ALERTS_ENABLED', false),
    'url' => env('CONNECTOR_ALERT_URL', 'https://clubedamedalha.com.br/index.php?route=extension/module/ow_connector_notify'),
    'secret' => env('CONNECTOR_ALERT_SECRET', ''),
    'connector' => env('CONNECTOR_ALERT_ID', 'cmb-connector'),
    'environment' => env('CONNECTOR_ALERT_ENVIRONMENT', 'production'),
];
