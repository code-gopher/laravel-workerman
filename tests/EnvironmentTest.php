<?php

declare(strict_types=1);

namespace CodeGopher\LaravelWorkerman\Tests;

use CodeGopher\LaravelWorkerman\Bootstrap;
use CodeGopher\LaravelWorkerman\Config\WorkermanConfig;
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

    /**
     * 还原全局环境，并删除本测试创建的临时应用目录。
     *
     * @return void
     * @throws \ReflectionException 环境仓库属性不可反射时抛出
     */
    protected function tearDown(): void
    {
        $property = $this->repositoryProperty();
        $property->setValue(null, $this->repository);
        Container::setInstance($this->container);
        $_ENV = $this->environment;
        $_SERVER = $this->server;

        foreach ($this->processEnvironment as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }

        $this->removeDirectory($this->basePath);
        parent::tearDown();
    }

    /**
     * Laravel 环境仓库应优先读取 putenv 写入的进程变量。
     *
     * @return void
     */
    public function testEnvironmentValueReadsProcessEnvironment(): void
    {
        putenv('WORKERMAN_HOST=127.0.0.9');

        self::assertSame('127.0.0.9', WorkermanConfig::getEnvironmentValue('WORKERMAN_HOST'));
    }

    /**
     * 配置文件中的显式值应覆盖同名环境变量。
     *
     * @return void
     */
    public function testConfigurationFileTakesPrecedenceOverEnvironment(): void
    {
        putenv('WORKERMAN_PORT=9001');
        file_put_contents($this->basePath . '/config/workerman.php', '<?php return ["port" => 9123];');

        $config = (new WorkermanConfig($this->basePath))->load();

        self::assertSame(9123, $config->getPort());
    }

    /**
     * Laravel 配置缓存存在时应优先于源配置文件。
     *
     * @return void
     */
    public function testCachedConfigurationTakesPrecedenceOverSourceFile(): void
    {
        file_put_contents($this->basePath . '/config/workerman.php', '<?php return ["port" => 9123];');
        file_put_contents(
            $this->basePath . '/bootstrap/cache/config.php',
            '<?php return ["workerman" => ["port" => 9234]];'
        );

        $config = (new WorkermanConfig($this->basePath))->load();

        self::assertSame(9234, $config->getPort());
    }

    /**
     * APP_CONFIG_CACHE 相对路径应相对项目根目录解析，绝对路径应原样保留。
     *
     * @return void
     */
    public function testCachedConfigurationPathSupportsRelativeAndAbsoluteValues(): void
    {
        putenv('APP_CONFIG_CACHE=var/cache/app.php');
        self::assertSame(
            $this->basePath . '/var/cache/app.php',
            (new WorkermanConfig($this->basePath))->getCachedConfigPath()
        );

        putenv('APP_CONFIG_CACHE=/tmp/workerman-config.php');
        self::assertSame('/tmp/workerman-config.php', (new WorkermanConfig($this->basePath))->getCachedConfigPath());
    }

    /**
     * APP_ENV 应选择 Laravel 约定的环境后缀文件。
     *
     * @return void
     * @throws \ReflectionException 受测私有方法不可反射时抛出
     */
    public function testApplicationEnvironmentSelectsSpecificEnvironmentFile(): void
    {
        file_put_contents($this->basePath . '/.env', 'APP_ENV=local');
        file_put_contents($this->basePath . '/.env.testing', 'APP_ENV=testing');
        putenv('APP_ENV=testing');
        $bootstrap = new Bootstrap($this->basePath, ['artisan', 'serve']);
        $method = new ReflectionMethod(Bootstrap::class, 'resolveEnvironmentFilePath');
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        self::assertSame($this->basePath . '/.env.testing', $method->invoke($bootstrap));
    }

    /**
     * 获取 Laravel Env 静态仓库属性，以隔离不可变仓库对测试的影响。
     *
     * @return ReflectionProperty
     * @throws \ReflectionException Env 仓库属性不存在时抛出
     */
    private function repositoryProperty(): ReflectionProperty
    {
        $property = new ReflectionProperty(Env::class, 'repository');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }

        return $property;
    }

    /**
     * 递归删除临时应用目录。
     *
     * @param string $directory 待删除目录
     * @return void
     */
    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
