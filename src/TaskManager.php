<?php

declare(strict_types=1);

namespace CodeGopher\LaravelWorkerman;

use Throwable;
use Workerman\Timer;
use CodeGopher\LaravelWorkerman\Contracts\TaskInterface;

/**
 * 定时任务管理器
 *
 * 加载配置的任务并将其注册到当前 Worker 的事件循环。
 */
final class TaskManager
{
    /** @var array<TaskInterface> 已加载的任务 */
    private $tasks = [];

    /**
     * 加载用户自定义定时任务。
     *
     * @param array<string> $tasks 任务类名列表
     *
     * @return void
     */
    public function loadTasks(array $tasks): void
    {
        foreach ($tasks as $class) {
            try {
                if (!class_exists($class)) {
                    Logger::warning("定时任务类不存在: {$class}");
                    continue;
                }

                $task = new $class();
                if (!($task instanceof TaskInterface)) {
                    Logger::warning("定时任务必须实现 TaskInterface 接口: {$class}");
                    continue;
                }

                $this->tasks[] = $task;
                Logger::info("已加载定时任务: {$class}");
            } catch (Throwable $e) {
                Logger::error("加载定时任务失败 [{$class}]: {$e->getMessage()}");
            }
        }

        if (count($this->tasks) > 0) {
            Logger::info('已加载 ' . count($this->tasks) . ' 个定时任务');
        }
    }

    /**
     * 注册已加载的定时任务。
     *
     * @return void
     */
    public function register(): void
    {
        foreach ($this->tasks as $task) {
            $interval = $task->getInterval();
            if ($interval <= 0) {
                Logger::warning('定时任务间隔必须大于 0: ' . get_class($task));
                continue;
            }

            Timer::add($interval, function () use ($task): void {
                try {
                    $task->handle();
                } catch (Throwable $e) {
                    Logger::error(sprintf('定时任务执行失败 [%s]: %s', get_class($task), $e->getMessage()));
                }
            });
        }
    }
}
