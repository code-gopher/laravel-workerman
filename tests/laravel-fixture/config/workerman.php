<?php

declare(strict_types=1);

return [
    'app' => require __DIR__ . '/app.php',
    'session' => require __DIR__ . '/session.php',
    'host' => '0.0.0.0',
    'port' => 18766,
    'workers' => 1,
    'max_requests' => (int) (getenv('WORKERMAN_TEST_MAX_REQUESTS') ?: 0),
    'debug' => true,
    'name' => 'laravel-workerman-integration',
    'static' => [
        'enable' => false,
        'path' => __DIR__ . '/../public',
    ],
    'log' => [
        'file' => __DIR__ . '/../storage/workerman.log',
    ],
    'cleaners' => [],
    'tasks' => [],
];
