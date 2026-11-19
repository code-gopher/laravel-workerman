<?php

declare(strict_types=1);

namespace CodeGopher\LaravelWorkerman\Tests;

use CodeGopher\LaravelWorkerman\Bootstrap;
use CodeGopher\LaravelWorkerman\Config\WorkermanConfig;
use Dotenv\Dotenv;
use Illuminate\Container\Container;
use Illuminate\Support\Env;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * 验证监听配置与 Laravel 环境选择、不可变变量及配置缓存保持一致。
 */
final class EnvironmentTest extends TestCase
{
    /** @var string 临时项目根目录 */
    private $basePath;

    /** @var array<string, mixed> 测试前的环境超全局变量 */
    private $environment;

    /** @var array<string, mixed> 测试前的服务器超全局变量 */
    private $server;

    /** @var array<string, string|false> 测试前的进程环境变量 */
    private $processEnvironment = [];

    /** @var Container 原容器实例，避免配置辅助函数受到其他测试影响 */
    private $container;

    /** @var mixed 原 Laravel 环境仓库 */
    private $repository;

    /**
     * 隔离环境来源，并在临时目录中建立最小应用而不引导任何业务服务。
     *
     * @return void
     * @throws \ReflectionException 环境仓库属性不可反射时抛出
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->environment = $_ENV;
        $this->server = $_SERVER;
        $this->container = Container::getInstance();
        Container::setInstance(new Container());
        $property = $this->repositoryProperty();
        $this->repository = $property->getValue();
        $property->setValue(null, null);

        foreach (['APP_ENV', 'APP_CONFIG_CACHE', 'WORKERMAN_HOST', 'WORKERMAN_PORT', 'WORKERMAN_WORKERS', 'WORKERMAN_MAX_REQUESTS', 'WORKERMAN_DEBUG', 'WORKERMAN_NAME'] as $key) {
            $this->processEnvironment[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        $this->basePath = sys_get_temp_dir() . '/workerman-environment-' . bin2hex(random_bytes(8));
        mkdir($this->basePath . '/bootstrap/cache', 0755, true);
        mkdir($this->basePath . '/config', 0755, true);
    }