<?php

declare(strict_types=1);

use CodeGopher\LaravelWorkerman\Config\WorkermanConfig;
use CodeGopher\LaravelWorkerman\WorkermanServer;

// 独立运行目录允许在同一 Docker 中验证新源码，不停止或覆盖已有服务的 PID 文件。
$framework = getenv('ACCEPTANCE_FRAMEWORK') ?: 'laravel';
if (!in_array($framework, ['laravel', 'lumen'], true)) {
    throw new InvalidArgumentException('Unknown acceptance framework');
}
$basePath = dirname(__DIR__) . '/' . $framework . '-fixture';
require $framework === 'lumen' ? $basePath . '/vendor/autoload.php' : dirname(__DIR__, 2) . '/vendor/autoload.php';
$config = (new WorkermanConfig($basePath))->load();
if (getenv('ACCEPTANCE_TASK') === '1') {
    require __DIR__ . '/TickTask.php';
    $tasks = new ReflectionProperty($config, 'tasks');
    if (PHP_VERSION_ID < 80100) {
        $tasks->setAccessible(true);
    }
    $tasks->setValue($config, [\CodeGopher\LaravelWorkerman\Tests\Acceptance\TickTask::class]);
}
$runtime = new ReflectionProperty($config, 'runtimeDir');
if (PHP_VERSION_ID < 80100) {
    $runtime->setAccessible(true);
}
$runtime->setValue($config, sys_get_temp_dir() . '/workerman-acceptance-' . $config->getPort());
(new WorkermanServer($config))->run();
