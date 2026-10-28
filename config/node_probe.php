<?php

return [
    'binary' => env('NODE_PROBE_BINARY', '/usr/local/bin/sing-box'),
    // Fixed HTTPS endpoints; the API accepts their keys, never arbitrary URLs.
    'targets' => [
        'cloudflare' => ['name' => 'Cloudflare', 'url' => 'https://cp.cloudflare.com/generate_204'],
        'google' => ['name' => 'Google', 'url' => 'https://www.gstatic.com/generate_204'],
    ],
    'ttl' => 1800,
];
