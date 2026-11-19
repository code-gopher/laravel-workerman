<?php

declare(strict_types=1);

namespace CodeGopher\LaravelWorkerman\Cleaners;

use CodeGopher\LaravelWorkerman\Logger;
use Throwable;
use CodeGopher\LaravelWorkerman\Contracts\CleanerInterface;

/**
 * Cookie 队列清理器
 */
class CookieCleaner implements CleanerInterface
{
    /**
     * 清除所有延迟响应 Cookie；失败由上层触发 Worker 淘汰。
     *
     * @param mixed $app 应用容器
     * @return void
     * @throws Throwable Cookie 存储不能被清理时抛出
     */
    public function clean($app): void
    {
        if (!$app->bound('cookie')) {
            return;
        }

        try {
            $cookie = $app->make('cookie');
            if (method_exists($cookie, 'flushQueuedCookies')) {
                $cookie->flushQueuedCookies();
            }
            if (method_exists($cookie, 'getQueuedCookies')) {
                $queuedCookies = $cookie->getQueuedCookies();
                if (method_exists($cookie, 'unqueue')) {
                    foreach ($queuedCookies as $name => $value) {
                        $cookie->unqueue($name);
                    }
                }
            }
        } catch (Throwable $e) {
            Logger::error('Cookie 队列清理失败: ' . $e->getMessage());
            // 清理失败必须上报，禁止把前一客户端排队的 Cookie 发送给后一客户端。
            throw $e;
        }
    }
}
