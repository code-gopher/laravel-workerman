<?php

declare(strict_types=1);

namespace CodeGopher\LaravelWorkerman\Cleaners;

use CodeGopher\LaravelWorkerman\Contracts\CleanerInterface;

/**
 * 请求实例清理器
 */
class RequestInstanceCleaner implements CleanerInterface
{
    /**
     * 释放请求作用域对象与路由 Controller，避免构造注入捕获上一请求。
     *
     * @param mixed $app 应用容器
     * @return void
     */
    public function clean($app): void
    {
        if (!method_exists($app, 'instance')) {
            return;
        }

        // Laravel 路由会缓存 Controller；构造函数注入的 Request 不能跨请求保留。
        if ($app->resolved('router')) {
            $router = $app->make('router');
            if (method_exists($router, 'getRoutes')) {
                foreach ($router->getRoutes() as $route) {
                    if (method_exists($route, 'flushController')) {
                        $route->flushController();
                    }
                }
            }
        }
        if (method_exists($app, 'forgetScopedInstances')) {
            $app->forgetScopedInstances();
        }

        // 保持 Laravel 容器的 request 别名始终可解析，同时丢弃上一请求对象中的全部状态。
        $request = new \Illuminate\Http\Request();
        foreach ([
            'request',
            'Illuminate\\Http\\Request',
            'Symfony\\Component\\HttpFoundation\\Request',
        ] as $abstract) {
            $app->instance($abstract, $request);
        }
    }
}
