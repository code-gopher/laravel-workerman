<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use CodeGopher\LaravelWorkerman\Bootstrap;

$basePath = __DIR__;
$cachePath = $basePath . '/storage/framework';
if (!is_dir($cachePath)) {
    mkdir($cachePath, 0775, true);
}

(new Bootstrap($basePath, $argv ?? []))->run();
