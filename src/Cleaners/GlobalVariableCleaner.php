<?php

declare(strict_types=1);

namespace CodeGopher\LaravelWorkerman\Cleaners;

use CodeGopher\LaravelWorkerman\Contracts\CleanerInterface;

/**
 * 全局变量清理器
 */
class GlobalVariableCleaner implements CleanerInterface
{
    public function clean($app): void
    {
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        $_FILES = [];
        $_COOKIE = [];
        $_SERVER = self::preservedServerVariables();
    }

    /**
     * 保留进程级 CLI 元数据，移除上一请求注入的 HTTP 服务变量。
     *
     * @return array<string, mixed>
     */
    private static function preservedServerVariables(): array
    {
        $preserved = [];
        foreach (['argv', 'argc'] as $key) {
            if (isset($_SERVER[$key])) {
                $preserved[$key] = $_SERVER[$key];
            }
        }

        return $preserved;
    }
}
