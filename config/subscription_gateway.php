<?php

return [
    'host' => env('SUBSCRIPTION_GATEWAY_HOST'),
    'port' => (int) env('SUBSCRIPTION_GATEWAY_PORT', 2443),
    'cert' => env('SUBSCRIPTION_GATEWAY_CERT', '/certs/fullchain.pem'),
    'key' => env('SUBSCRIPTION_GATEWAY_KEY', '/certs/privkey.pem'),
];
