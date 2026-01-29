<?php

declare(strict_types=1);

namespace CodeGopher\LaravelWorkerman\Cleaners;

use CodeGopher\LaravelWorkerman\Contracts\CleanerInterface;

/**
 * 请求实例清理器
 */
class RequestInstanceCleaner implements CleanerInterface
{
    public function clean($app): void
    {
        $abstracts = [
            'request',
            'Illuminate\\Http\\Request',
            'Symfony\\Component\\HttpFoundation\\Request',
        ];

        foreach ($abstracts as $abstract) {
            if ($app->resolved($abstract)) {
                $app->forgetInstance($abstract);
            }
        }
    }
}
