<?php

declare(strict_types=1);

namespace CodeGopher\LaravelWorkerman\Tests\Acceptance;

use CodeGopher\LaravelWorkerman\Contracts\TaskInterface;

/**
 * 用进程号和时间戳记录真实定时执行，检查 HTTP 轮换不会重建调度器。
 */
final class TickTask implements TaskInterface
{
    /**
     * 使用短周期在有限验收时间内覆盖多个 HTTP 轮换。
     *
     * @return float
     */
    public function getInterval(): float
    {
        return 0.2;
    }

    /**
     * 模拟耗时任务并记录执行进程，输出只写入测试临时目录。
     *
     * @return void
     */
    public function handle(): void
    {
        file_put_contents(getenv('ACCEPTANCE_TICKS_PATH') ?: '/tmp/workerman-acceptance-ticks.log', getmypid() . ' ' . microtime(true) . "\n", FILE_APPEND | LOCK_EX);
        usleep(250000);
    }
}
