<?php

declare(strict_types=1);

return [
    'host' => '0.0.0.0',
    'port' => 18767,
    'workers' => (int) (getenv('WORKERMAN_WORKERS') ?: 4),
    'max_requests' => (int) (getenv('WORKERMAN_MAX_REQUESTS') ?: 0),
    'debug' => false,
    'name' => 'lumen-workerman-acceptance',
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
