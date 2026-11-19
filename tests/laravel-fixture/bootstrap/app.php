<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__ . '/../routes/web.php')
    ->withMiddleware(static function (Middleware $middleware): void {
        // 原始 Cookie 隔离探针不参与应用加密，其余 Session Cookie 保持正常中间件处理。
        $middleware->encryptCookies(except: ['acceptance_marker']);
    })
    ->withExceptions(static function (Exceptions $exceptions): void {
        // 路由按需由 Illuminate RouteServiceProvider 加载；配置闭包只避免默认错误视图依赖。
        $exceptions->render(static function (Throwable $exception, Request $request) {
            if ($exception instanceof HttpExceptionInterface) {
                return new Response($exception->getMessage(), $exception->getStatusCode());
            }

            return new Response($exception->getMessage(), 500);
        });
    })
    ->create();
