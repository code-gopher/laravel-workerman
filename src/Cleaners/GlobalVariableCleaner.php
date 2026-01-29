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
    }
}
