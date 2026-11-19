<?php

declare(strict_types=1);

namespace CodeGopher\LaravelWorkerman\Cleaners;

use Illuminate\Http\Request;
use ReflectionObject;
use CodeGopher\LaravelWorkerman\Contracts\CleanerInterface;

/**
 * 恢复 URL 生成器的应用级基线，防止租户默认参数和强制域名跨请求残留。
 */
class UrlGeneratorCleaner implements CleanerInterface
{
    /** @var array<string, mixed> 启动期 URL 配置 */
    private $baseline = [];

    /**
     * 在真实请求到来之前捕获提供者配置。
     *
     * @param mixed $app 框架容器
     * @return void
     */
    public function captureBaseline($app): void
    {
        $url = $app->make('url');
        $reflection = new ReflectionObject($url);
        foreach (['routeGenerator', 'forcedRoot', 'forceScheme', 'assetRoot', 'formatHostUsing', 'formatPathUsing'] as $name) {
            if ($reflection->hasProperty($name)) {
                $property = $reflection->getProperty($name);
                if (PHP_VERSION_ID < 80100) {
                    $property->setAccessible(true);
                }
                $value = $property->getValue($url);
                $this->baseline[$name] = is_object($value) && !($value instanceof \Closure) ? clone $value : $value;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function clean($app): void
    {
        if (!$app->resolved('url')) {
            return;
        }
        $url = $app->make('url');
        $reflection = new ReflectionObject($url);
        foreach ($this->baseline as $name => $value) {
            $property = $reflection->getProperty($name);
            if (PHP_VERSION_ID < 80100) {
                $property->setAccessible(true);
            }
            $property->setValue($url, is_object($value) && !($value instanceof \Closure) ? clone $value : $value);
        }
        if (method_exists($url, 'setRequest')) {
            $url->setRequest(new Request());
        }
    }
}
