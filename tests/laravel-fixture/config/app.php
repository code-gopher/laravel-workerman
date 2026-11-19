<?php

declare(strict_types=1);

return [
    'name' => 'Laravel Workerman Fixture',
    'env' => 'testing',
    'debug' => true,
    'url' => 'http://localhost',
    'timezone' => 'UTC',
    'locale' => 'en',
    'fallback_locale' => 'en',
    'faker_locale' => 'en_US',
    'key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'cipher' => 'AES-256-CBC',
    'session' => [
        'driver' => 'array',
        'lifetime' => 120,
        'expire_on_close' => false,
        'encrypt' => false,
        'files' => __DIR__ . '/../storage/framework/sessions',
        'connection' => null,
        'table' => 'sessions',
        'store' => null,
        'lottery' => [2, 100],
        'cookie' => 'laravel_workerman_session',
        'path' => '/',
        'domain' => null,
        'secure' => false,
        'http_only' => true,
        'same_site' => 'lax',
        'partitioned' => false,
    ],
];
