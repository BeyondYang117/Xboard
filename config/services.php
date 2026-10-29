<?php

return [

    'linuxdo' => [
        'enabled' => env('LINUXDO_ENABLED', false),
        'client_id' => env('LINUXDO_CLIENT_ID', ''),
        'client_secret' => env('LINUXDO_CLIENT_SECRET', ''),
        'redirect_uri' => env('LINUXDO_REDIRECT_URI', ''),
        'min_trust_level' => (int) env('LINUXDO_MIN_TRUST_LEVEL', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_V2BOARD_REGION', 'us-east-1'),
    ],

];
