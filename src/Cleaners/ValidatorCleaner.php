<?php

declare(strict_types=1);

namespace CodeGopher\LaravelWorkerman\Cleaners;

use CodeGopher\LaravelWorkerman\Contracts\CleanerInterface;

/**
 * 保留验证工厂中服务提供者注册的规则；单次验证器由工厂按请求创建。
 */
class ValidatorCleaner implements CleanerInterface
{
    /**
     * {@inheritdoc}
     */
    public function clean($app): void
    {
        // Factory 是应用级规则注册表，不包含由 make() 返回的请求级 Validator。
        // 删除单例会丢失 boot() 注册的自定义规则，不能把它当请求缓存清除。
    }
}
