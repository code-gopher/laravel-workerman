<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use CodeGopher\LaravelWorkerman\Bootstrap;

(new Bootstrap(__DIR__, $argv ?? []))->run();
