<?php

declare(strict_types=1);

namespace CodeGopher\LaravelWorkerman\Cleaners;

use Throwable;
use CodeGopher\LaravelWorkerman\Contracts\CleanerInterface;
use CodeGopher\LaravelWorkerman\Logger;

/**
 * 数据库清理器
 */
class DatabaseCleaner implements CleanerInterface
{
    public function clean($app): void
    {
        if (!$app->bound('db')) {
            return;
        }

        try {
            $db = $app->make('db');
            foreach ($db->getConnections() as $connection) {
                $connection->flushQueryLog();

                if (method_exists($connection, 'transactionLevel') && $connection->transactionLevel() > 0) {
                    Logger::warning('检测到未提交的事务，正在回滚...');
                    try {
                        // 直接回滚最外层，避免已丢失的嵌套保存点阻断整笔事务清理。
                        $connection->rollBack(0);
                        if ($connection->transactionLevel() !== 0) {
                            throw new \RuntimeException('事务层级未能归零');
                        }
                    } catch (Throwable $e) {
                        Logger::error('事务回滚失败: ' . $e->getMessage());
                        // 失败连接不得被下一请求复用；重新建立连接也会回收服务端事务。
                        $connection->disconnect();
                        throw $e;
                    }
                }
            }
        } catch (Throwable $e) {
            Logger::error('数据库请求状态清理失败: ' . $e->getMessage());
            throw $e;
        }
    }
}
