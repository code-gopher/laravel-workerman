<?php

declare(strict_types=1);

namespace CodeGopher\LaravelWorkerman\Cleaners;

use Throwable;
use CodeGopher\LaravelWorkerman\Contracts\CleanerInterface;

/**
 * URL 生成器清理器
 */
class UrlGeneratorCleaner implements CleanerInterface
{
    public function clean($app): void
    {
        if (!$app->bound('url')) {
            return;
        }

        try {
            $urlGenerator = $app->make('url');
            if (method_exists($urlGenerator, 'setRequest')) {
                $urlGenerator->setRequest(null);
            }
        } catch (Throwable $e) {
            // 忽略
        }
    }
}
