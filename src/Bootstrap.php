<?php

declare(strict_types=1);

namespace CodeGopher\LaravelWorkerman;

use Workerman\Worker;
use Throwable;
use CodeGopher\LaravelWorkerman\Config\WorkermanConfig;

/**
 * Workerman 引导类
 *
 * 负责环境检查、命令行参数解析和服务器启动
 * 作为入口脚本的封装，提供清晰的启动流程
 */
final class Bootstrap
{
    /** @var string 项目根目录 */
    private $basePath;

    /** @var array<string> 命令行参数 */
    private $argv;

    /** @var string Workerman 版本要求 */
    private const WORKERMAN_MIN_VERSION = '4.0.0';

    /**
     * 构造函数
     *
     * @param string        $basePath 项目根目录
     * @param array<string> $argv     命令行参数 (通常是 $GLOBALS['argv'])
     */
    public function __construct(string $basePath, array $argv = [])
    {
        $this->basePath = $basePath;
        $this->argv = $argv;
    }

    /**
     * 运行 Workerman 服务器
     *
     * @return void
     */
    public function run(): void
    {
        $this->defineConstants();
        $this->validateEnvironment();
        $this->checkWindowsCommand();

        $config = $this->loadConfig();
        $server = new WorkermanServer($config);
        $server->run();
    }

    /**
     * 定义必要的常量
     *
     * @return void
     */
    private function defineConstants(): void
    {
        if (!defined('WORKERMAN_RUNNING')) {
            define('WORKERMAN_RUNNING', true);
        }
    }

    /**
     * 验证运行环境
     *
     * @return void
     * @throws \RuntimeException 环境不满足要求时抛出
     */
    private function validateEnvironment(): void
    {
        // 检查 CLI 模式
        if (PHP_SAPI !== 'cli') {
            $this->exitWithError('请使用命令行运行: php workerman.php start');
        }

        // 检查 Workerman 是否安装
        if (!class_exists(Worker::class)) {
            $this->exitWithError('错误: 未找到 Workerman，请执行 composer require workerman/workerman');
        }

        // 检查 Workerman 版本
        if (version_compare(Worker::VERSION, self::WORKERMAN_MIN_VERSION, '<')) {
            $this->exitWithError(sprintf(
                '错误: Workerman 版本过低，需要 >= %s，当前版本: %s',
                self::WORKERMAN_MIN_VERSION,
                Worker::VERSION
            ));
        }
    }

    /**
     * 检查 Windows 不支持的命令
     *
     * @return void
     */
    private function checkWindowsCommand(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return;
        }

        $command = $this->argv[1] ?? '';
        $unsupportedCommands = ['stop', 'restart', 'reload', 'status'];

        if (in_array($command, $unsupportedCommands, true)) {
            $this->exitWithError("Windows 不支持 {$command} 命令，请使用 Ctrl+C 停止服务");
        }
    }

    /**
     * 加载配置
     *
     * @return WorkermanConfig
     */
    private function loadConfig(): WorkermanConfig
    {
        $this->loadEnvironment();
        $cliOptions = getopt('p:w:m:', ['port:', 'workers:', 'max:', 'debug']);

        return (new WorkermanConfig($this->basePath))
            ->load($cliOptions ?: []);
    }

    /**
     * 在解析监听配置前加载项目环境变量，避免 Laravel Worker 尚未启动时读取到默认值。
     *
     * @return void
     * @throws \InvalidArgumentException APP_CONFIG_CACHE 路径无效时抛出
     */
    private function loadEnvironment(): void
    {
        // Laravel 缓存模式不加载任何环境文件，避免预加载把过期值注入后续 Worker。
        if (is_file((new WorkermanConfig($this->basePath))->getCachedConfigPath())) {
            return;
        }

        $dotenvClass = 'Dotenv\\Dotenv';
        if (!class_exists($dotenvClass)) {
            return;
        }

        if (!method_exists($dotenvClass, 'createImmutable') && !method_exists($dotenvClass, 'create')) {
            Logger::warning('当前 phpdotenv 版本不支持兼容的 create API，跳过预加载环境文件');
            return;
        }

        try {
            $environmentFile = $this->resolveEnvironmentFilePath();
            if (!is_file($environmentFile)) {
                return;
            }
            $environmentFileName = basename($environmentFile);

            // 显式把宿主 Laravel 选择的文件名传给 Dotenv，不能只检查自定义文件却加载默认 .env。
            if (method_exists('Illuminate\\Support\\Env', 'getRepository')) {
                // Dotenv 4/5 共用 Laravel 的不可变仓库，保留宿主的 putenv 策略和外部变量优先级。
                $dotenv = $dotenvClass::create(\Illuminate\Support\Env::getRepository(), $this->basePath, $environmentFileName);
            } elseif (method_exists($dotenvClass, 'createImmutable')) {
                $dotenv = $dotenvClass::createImmutable($this->basePath, $environmentFileName);
            } elseif (method_exists($dotenvClass, 'create')) {
                $dotenv = $dotenvClass::create($this->basePath, $environmentFileName);
            } else {
                return;
            }

            if (method_exists($dotenv, 'safeLoad')) {
                $dotenv->safeLoad();
            } else {
                $dotenv->load();
            }
        } catch (Throwable $e) {
            $this->exitWithError('加载项目环境变量失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取应用 bootstrap 配置的环境文件路径。
     *
     * @return string
     * @throws \InvalidArgumentException 环境名称包含路径分隔符时抛出
     */
    private function resolveEnvironmentFilePath(): string
    {
        $environmentFile = '.env';
        $applicationFile = $this->basePath . '/bootstrap/app.php';
        if (is_file($applicationFile)) {
            $application = file_get_contents($applicationFile);
            if (is_string($application) && preg_match('/loadEnvironmentFrom\\s*\\(\\s*[\'"]([^\'"]+)[\'"]\\s*\\)/', $application, $matches)) {
                $environmentFile = $matches[1];
            }
        }

        if ($environmentFile === '' || basename($environmentFile) !== $environmentFile || strpos($environmentFile, '..') !== false) {
            $this->exitWithError('环境文件名不安全');
        }

        $environmentFilePath = $this->basePath . DIRECTORY_SEPARATOR . $environmentFile;
        $input = new \Symfony\Component\Console\Input\ArgvInput($this->argv ?: null);
        if ($input->hasParameterOption('--env')) {
            $commandEnvironment = $input->getParameterOption('--env');
            if (is_string($commandEnvironment)) {
                $specificFile = $this->resolveSpecificEnvironmentFile($environmentFilePath, $commandEnvironment);
                if ($specificFile !== null) {
                    return $specificFile;
                }
            }
        }

        // 只使用加载文件前已存在的 APP_ENV，不能先读 .env 再选择，否则不可变仓库会锁定错误值。
        $environment = WorkermanConfig::getEnvironmentValue('APP_ENV');
        if (is_string($environment) && $environment !== '') {
            $specificFile = $this->resolveSpecificEnvironmentFile($environmentFilePath, $environment);
            if ($specificFile !== null) {
                return $specificFile;
            }
        }

        return $environmentFilePath;
    }

    /**
     * 查找环境后缀文件；文件缺失时允许按 Laravel 规则继续回退。
     *
     * @param string $environmentFilePath 基础环境文件路径
     * @param string $environment 环境名称
     *
     * @return string|null 存在的环境文件路径；无匹配文件时返回 null
     * @throws \InvalidArgumentException 环境名称包含路径分隔符时抛出
     */
    private function resolveSpecificEnvironmentFile(string $environmentFilePath, string $environment): ?string
    {
        // 环境名仅用作文件后缀，不能借助目录分隔符读取项目外的文件。
        if (strpos($environment, '/') !== false || strpos($environment, '\\') !== false || strpos($environment, "\0") !== false) {
            throw new \InvalidArgumentException('环境名称不能包含路径分隔符或空字节');
        }

        $specificFile = $environmentFilePath . '.' . $environment;

        return is_file($specificFile) ? $specificFile : null;
    }

    /**
     * 输出错误信息并退出
     *
     * @param string $message 错误信息
     *
     * @return void
     */
    private function exitWithError(string $message): void
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}
