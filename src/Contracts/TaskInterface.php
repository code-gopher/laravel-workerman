<?php

declare(strict_types=1);

namespace CodeGopher\LaravelWorkerman\Contracts;

/**
 * 定时任务接口
 *
 * 定时任务由独立的 Workerman Worker 执行。
 */
interface TaskInterface
{
    /**
     * 定时任务执行间隔，单位为秒。
     *
     * @return float
     */
    public function getInterval(): float;

    /**
     * 执行任务。
     *
     * @return void
     */
    public function handle(): void;
}
