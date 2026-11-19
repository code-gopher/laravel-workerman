<?php

declare(strict_types=1);

namespace CodeGopher\LaravelWorkerman\Cleaners;

use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use ReflectionObject;
use CodeGopher\LaravelWorkerman\Contracts\CleanerInterface;

/**
 * 重置已解析 Guard 的请求状态，不创建未使用的 Guard 或累积请求重绑定回调。
 */
class AuthCleaner implements CleanerInterface
{
    /**
     * {@inheritdoc}
     */
    public function clean($app): void
    {
        if (!$app->resolved('auth')) {
            return;
        }

        $auth = $app->make('auth');
        $reflection = new ReflectionObject($auth);
        if (!$reflection->hasProperty('guards')) {
            throw new \RuntimeException('认证管理器不提供可隔离的 Guard 缓存');
        }
        $property = $reflection->getProperty('guards');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        foreach ($property->getValue($auth) as $guard) {
            if (method_exists($guard, 'setRequest')) {
                $guard->setRequest(new Request());
            }
            if (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            } else {
                // 旧版 GuardHelpers 没有 forgetUser，直接重置属性避免 setUser 的非空类型约束。
                $this->resetProperty($guard, 'user', null);
            }
            if ($guard instanceof SessionGuard) {
                // 这些标志由 logout 和 remember-me 修改，forgetUser 本身不会清除。
                foreach (['loggedOut', 'recallAttempted', 'viaRemember'] as $name) {
                    $this->resetProperty($guard, $name, false);
                }
                $this->resetProperty($guard, 'lastAttempted', null);
            }
        }
        // auth.driver 是默认 Guard 的容器缓存，下一请求应按已恢复的默认配置重新解析。
        $app->forgetInstance('auth.driver');
    }

    /**
     * 兼容不同框架版本的受保护请求状态属性。
     *
     * @param object $target Guard 实例
     * @param string $name 属性名
     * @param mixed $value 请求结束后的初始值
     * @return void
     */
    private function resetProperty($target, string $name, $value): void
    {
        $reflection = new ReflectionObject($target);
        if (!$reflection->hasProperty($name)) {
            return;
        }
        $property = $reflection->getProperty($name);
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $property->setValue($target, $value);
    }
}
