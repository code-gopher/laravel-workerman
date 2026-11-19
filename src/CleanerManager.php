<?php

declare(strict_types=1);

namespace CodeGopher\LaravelWorkerman;

use Throwable;
use CodeGopher\LaravelWorkerman\Contracts\CleanerInterface;
use CodeGopher\LaravelWorkerman\Cleaners\GlobalVariableCleaner;
use CodeGopher\LaravelWorkerman\Cleaners\RequestInstanceCleaner;
use CodeGopher\LaravelWorkerman\Cleaners\FacadeCleaner;
use CodeGopher\LaravelWorkerman\Cleaners\SessionCleaner;
use CodeGopher\LaravelWorkerman\Cleaners\AuthCleaner;
use CodeGopher\LaravelWorkerman\Cleaners\CookieCleaner;
use CodeGopher\LaravelWorkerman\Cleaners\ValidatorCleaner;
use CodeGopher\LaravelWorkerman\Cleaners\UrlGeneratorCleaner;
use CodeGopher\LaravelWorkerman\Cleaners\DatabaseCleaner;

/**
 * 清理器管理器
 *
 * 负责加载和执行清理器（内置 + 用户自定义）
 */
class CleanerManager
{
    /** @var array<CleanerInterface> 清理器实例列表 */
    private $cleaners = [];

    /**
     * 内置清理器列表
     *
     * @var array<string>
     */
    private const BUILTIN_CLEANERS = [
        GlobalVariableCleaner::class,
        RequestInstanceCleaner::class,
        FacadeCleaner::class,
        SessionCleaner::class,
        AuthCleaner::class,
        CookieCleaner::class,
        ValidatorCleaner::class,
        UrlGeneratorCleaner::class,
        DatabaseCleaner::class,
    ];

    /**
     * 在首个业务请求前记录应用级清理基线。
     *
     * @param mixed $app 可选框架容器
     */
    public function __construct($app = null)
    {
        foreach (self::BUILTIN_CLEANERS as $class) {
            $cleaner = new $class();
            if ($app !== null && $cleaner instanceof UrlGeneratorCleaner) {
                $cleaner->captureBaseline($app);
            }
            $this->cleaners[] = $cleaner;
        }
    }

    /**
     * 加载用户自定义清理器
     *
     * @param array<string> $cleaners 清理器类名列表
     *
     * @return void
     */
    public function loadCleaners(array $cleaners): void
    {
        foreach ($cleaners as $class) {
            try {
                if (!is_string($class) || !class_exists($class)) {
                    throw new \InvalidArgumentException('清理器类不存在或类名无效');
                }

                $cleaner = new $class();

                if (!($cleaner instanceof CleanerInterface)) {
                    throw new \InvalidArgumentException('清理器必须实现 CleanerInterface 接口');
                }

                $this->cleaners[] = $cleaner;
            } catch (Throwable $e) {
                // 配置的隔离器缺失时启动必须失败，不能带着不完整的隔离链上线。
                throw new \RuntimeException('加载清理器失败: ' . $e->getMessage(), 0, $e);
            }
        }

        $customCount = count($cleaners);
        if ($customCount > 0) {
            Logger::info("已加载 {$customCount} 个自定义清理器");
        }
    }

    /**
     * 获取已加载的清理器数量（包括内置）
     *
     * @return int
     */
    public function getCleanerCount(): int
    {
        return count($this->cleaners);
    }

    /**
     * 执行请求清理
     *
     * @param mixed $app 应用实例
     *
     * @return void
     */
    public function cleanup($app): void
    {
        $failure = null;
        foreach ($this->cleaners as $cleaner) {
            try {
                $cleaner->clean($app);
            } catch (Throwable $e) {
                Logger::error('清理器执行失败: ' . $e->getMessage());
                $failure = $failure ?? $e;
            }
        }
        if ($failure !== null) {
            // 继续运行所有清理器后上报，由请求管理器淘汰不再可信的 Worker。
            throw new \RuntimeException('请求隔离未完成', 0, $failure);
        }
    }
}
