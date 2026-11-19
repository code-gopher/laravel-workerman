<?php

declare(strict_types=1);

namespace CodeGopher\LaravelWorkerman\Cleaners;

use CodeGopher\LaravelWorkerman\Contracts\CleanerInterface;

/**
 * 重置已创建的会话存储，保留 Guard 引用但不重复保存和老化 flash。
 */
class SessionCleaner implements CleanerInterface
{
    /**
     * {@inheritdoc}
     */
    public function clean($app): void
    {
        if (!$app->resolved('session')) {
            return;
        }
        $manager = $app->make('session');
        $drivers = method_exists($manager, 'getDrivers') ? $manager->getDrivers() : [];
        foreach ($drivers as $driver) {
            try {
                // 仅补保存绕过 StartSession、但明确已启动的会话。
                if ($driver->isStarted()) {
                    $driver->save();
                }
            } finally {
                // 数据库处理器的 exists 标志也必须恢复，否则新客户端会 UPDATE 不存在的行。
                $driver->flush();
                $driver->setExists(false);
                $driver->setId('');
            }
        }
    }
}
