<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use CodeGopher\LaravelWorkerman\AppManager;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

$manager = (new ReflectionClass(AppManager::class))->newInstanceWithoutConstructor();
$converter = new ReflectionMethod(AppManager::class, 'convertResponse');
$converter->setAccessible(true);
$worker = new Worker('http://0.0.0.0:18765');
$worker->count = 1;
$worker->onMessage = static function ($connection, Request $request) use ($manager, $converter): void {
    $response = new SymfonyResponse('wire-body');
    if ($request->uri() === '/cookie') {
        $response->headers->setCookie(new Cookie('session', 'wire', time() + 3600));
    }

    /** @var Response $workermanResponse */
    $workermanResponse = $converter->invoke($manager, $response, $request);
    $connection->send($workermanResponse);
};

Worker::runAll();
