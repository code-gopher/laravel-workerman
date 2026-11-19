<?php

declare(strict_types=1);

use CodeGopher\LaravelWorkerman\Bootstrap;

require dirname(__DIR__) . '/vendor/autoload.php';

// 测试夹具使用可写的 bootstrap cache 目录，满足 Laravel ProviderRepository 的启动契约。
$cachePath = __DIR__ . '/laravel-fixture/bootstrap/cache';
if (!is_dir($cachePath)) {
    mkdir($cachePath, 0775, true);
}

(new Bootstrap(__DIR__ . '/laravel-fixture', []))->run();
