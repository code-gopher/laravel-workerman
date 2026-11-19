<?php

declare(strict_types=1);

namespace CodeGopher\LaravelWorkerman\Config;

/**
 * Workerman 配置管理器
 *
 * 负责加载和管理 Workerman 服务器的所有配置项
 * 支持配置文件、环境变量、命令行参数三级覆盖
 */
final class WorkermanConfig
{
    /** @var string 服务器监听地址 */
    private $host;

    /** @var int 服务器监听端口 */
    private $port;

    /** @var int Worker 进程数量 */
    private $workerCount;

    /** @var int 单个 Worker 最大请求数 */
    private $maxRequests;

    /** @var bool 是否启用调试模式 */
    private $debug;

    /** @var string Worker 进程名称 */
    private $name;

    /** @var bool 是否启用静态文件服务 */
    private $staticEnable;

    /** @var string 静态文件目录路径 */
    private $staticPath;

    /** @var string 日志文件路径 */
    private $logFile;

    /** @var string 项目根目录 */
    private $basePath;

    /** @var string 运行时目录 */
    private $runtimeDir;

    /** @var array<string> 自定义清理器类名列表 */
    private $cleaners = [];

    /** @var array<string> 定时任务类名列表 */
    private $tasks = [];

    /**
     * 配置构造函数
     *
     * @param string $basePath 项目根目录
     */
    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
        $this->runtimeDir = $basePath . '/storage/workerman';
    }

    /**
     * 从配置文件和命令行参数加载配置
     *
     * @param array<string, mixed> $cliOptions 命令行参数 (getopt 返回值)
     *
     * @return self
     */
    public function load(array $cliOptions = []): self
    {
        // 加载配置文件
        $config = $this->loadConfigFile();

        // 解析各项配置（优先级：命令行 > 配置文件 > 环境变量 > 默认值）
        $this->host = $this->resolveString($config, 'host', 'WORKERMAN_HOST', '0.0.0.0');
        $this->port = $this->resolvePort($config, $cliOptions);
        $this->workerCount = $this->resolveWorkerCount($config, $cliOptions);
        $this->maxRequests = $this->resolveMaxRequests($config, $cliOptions);
        $this->debug = $this->resolveDebug($config, $cliOptions);
        $this->name = $this->resolveString($config, 'name', 'WORKERMAN_NAME', 'laravel-workerman');

        // 配置结构在进入运行期前验证，避免错误值静默转换为默认值或触发 TypeError。
        $staticConfig = $config['static'] ?? [];
        $logConfig = $config['log'] ?? [];
        if (!is_array($staticConfig) || !is_array($logConfig)) {
            throw new \InvalidArgumentException('Workerman static 和 log 配置必须是数组');
        }

        $staticEnabled = $staticConfig['enable'] ?? true;
        if (!is_bool($staticEnabled)) {
            throw new \InvalidArgumentException('Workerman static.enable 必须是布尔值');
        }
        $this->staticEnable = $staticEnabled;

        $staticPath = $staticConfig['path'] ?? $this->basePath . '/public';
        $logFile = $logConfig['file'] ?? $this->runtimeDir . '/workerman.log';
        if (!is_string($staticPath) || $staticPath === '' || !is_string($logFile) || $logFile === '') {
            throw new \InvalidArgumentException('Workerman 静态目录和日志路径必须是非空字符串');
        }
        $this->staticPath = $staticPath;
        $this->logFile = $logFile;

        $this->cleaners = $config['cleaners'] ?? [];
        $this->tasks = $config['tasks'] ?? [];
        if (!is_array($this->cleaners) || !is_array($this->tasks)) {
            throw new \InvalidArgumentException('Workerman cleaners 和 tasks 配置必须是数组');
        }

        return $this;
    }

    /**
     * 读取 Laravel 与 Dotenv 使用的环境值，兼容不调用 putenv 的不可变加载器。
     *
     * @param string $name 环境变量名
     *
     * @return mixed 环境值；未定义时返回 null
     */
    public static function getEnvironmentValue(string $name)
    {
        if (class_exists('Illuminate\\Support\\Env')) {
            return \Illuminate\Support\Env::get($name);
        }

        // 独立使用时也遵循 Dotenv 的读取顺序，不能仅依赖进程环境。
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return $value === false ? null : $value;
    }

    /**
     * 解析 Laravel 默认或 APP_CONFIG_CACHE 指定的配置缓存路径。
     *
     * @return string 配置缓存的绝对路径
     * @throws \InvalidArgumentException 缓存路径不是非空字符串时抛出
     */
    public function getCachedConfigPath(): string
    {
        $path = self::getEnvironmentValue('APP_CONFIG_CACHE');
        if ($path === null) {
            return $this->basePath . '/bootstrap/cache/config.php';
        }

        if (!is_string($path) || $path === '') {
            throw new \InvalidArgumentException('APP_CONFIG_CACHE 必须是非空字符串');
        }

        // 同时识别 Unix、UNC 和 Windows 盘符，避免在跨平台启动脚本中重复拼接根目录。
        if ($path[0] === '/' || $path[0] === '\\'
            || (preg_match('/^[A-Za-z]:/', $path) && (substr($path, 2, 1) === '/' || substr($path, 2, 1) === '\\'))
        ) {
            return $path;
        }

        return $this->basePath . '/' . $path;
    }

    /**
     * 优先读取 Laravel 配置缓存，避免重新执行配置文件造成监听参数与 Worker 不一致。
     *
     * @return array<string, mixed>
     * @throws \InvalidArgumentException 缓存结构或 Workerman 配置不是数组时抛出
     */
    private function loadConfigFile(): array
    {
        $cachedConfigFile = $this->getCachedConfigPath();
        if (is_file($cachedConfigFile)) {
            $cachedConfig = require $cachedConfigFile;
            if (!is_array($cachedConfig)) {
                throw new \InvalidArgumentException('Laravel 配置缓存必须是数组');
            }

            // 缓存中缺少 workerman 时也不回退到源配置，与 Laravel 缓存模式保持一致。
            $config = $cachedConfig['workerman'] ?? [];
            if (!is_array($config)) {
                throw new \InvalidArgumentException('缓存中的 Workerman 配置必须是数组');
            }

            return $config;
        }

        $configFile = $this->basePath . '/config/workerman.php';

        if (file_exists($configFile)) {
            return require $configFile;
        }

        if (function_exists('config') && function_exists('app')) {
            $application = app();
            if (is_object($application)
                && method_exists($application, 'bound')
                && $application->bound('config')
            ) {
                $frameworkConfig = config('workerman', []);
                if (is_array($frameworkConfig)) {
                    return $frameworkConfig;
                }
            }
        }

        return [];
    }

    /**
     * 解析字符串类型配置
     *
     * @param array<string, mixed> $config  配置数组
     * @param string               $key     配置键名
     * @param string               $envKey  环境变量名
     * @param string               $default 默认值
     *
     * @return string
     */
    private function resolveString(array $config, string $key, string $envKey, string $default): string
    {
        $value = $config[$key] ?? self::getEnvironmentValue($envKey);
        if ($value === false || $value === null || $value === '') {
            $value = $default;
        }

        if (!is_scalar($value)) {
            throw new \InvalidArgumentException("Workerman {$key} 配置必须是标量");
        }

        return (string) $value;
    }

    /**
     * 解析端口配置
     *
     * @param array<string, mixed> $config     配置数组
     * @param array<string, mixed> $cliOptions 命令行参数
     *
     * @return int
     */
    private function resolvePort(array $config, array $cliOptions): int
    {
        $port = $this->resolveIntegerOption(
            $cliOptions['p'] ?? $cliOptions['port'] ?? $config['port'] ?? self::getEnvironmentValue('WORKERMAN_PORT'),
            8080,
            'port'
        );

        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Workerman port 必须在 1 到 65535 之间');
        }

        return $port;
    }

    /**
     * 解析 Worker 进程数配置
     *
     * @param array<string, mixed> $config     配置数组
     * @param array<string, mixed> $cliOptions 命令行参数
     *
     * @return int
     */
    private function resolveWorkerCount(array $config, array $cliOptions): int
    {
        $workers = $this->resolveIntegerOption(
            $cliOptions['w'] ?? $cliOptions['workers'] ?? $config['workers'] ?? self::getEnvironmentValue('WORKERMAN_WORKERS'),
            4,
            'workers'
        );
        if ($workers < 1) {
            throw new \InvalidArgumentException('Workerman workers 必须大于 0');
        }

        // Windows 系统只能使用单进程
        if (PHP_OS_FAMILY === 'Windows') {
            return 1;
        }

        return $workers;
    }

    /**
     * 解析最大请求数配置
     *
     * @param array<string, mixed> $config     配置数组
     * @param array<string, mixed> $cliOptions 命令行参数
     *
     * @return int
     */
    private function resolveMaxRequests(array $config, array $cliOptions): int
    {
        $maxRequests = $this->resolveIntegerOption(
            $cliOptions['m'] ?? $cliOptions['max'] ?? $config['max_requests'] ?? self::getEnvironmentValue('WORKERMAN_MAX_REQUESTS'),
            10000,
            'max_requests'
        );
        // 0 表示禁用自动轮换，与 Workerman 的 max_request 约定一致。
        if ($maxRequests < 0) {
            throw new \InvalidArgumentException('Workerman max_requests 不能小于 0');
        }

        return $maxRequests;
    }

    /**
     * 解析调试模式配置
     *
     * @param array<string, mixed> $config     配置数组
     * @param array<string, mixed> $cliOptions 命令行参数
     *
     * @return bool
     */
    private function resolveDebug(array $config, array $cliOptions): bool
    {
        if (isset($cliOptions['debug'])) {
            return true;
        }

        $value = $config['debug'] ?? self::getEnvironmentValue('WORKERMAN_DEBUG');
        if ($value === false || $value === null || $value === '') {
            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (!is_scalar($value)) {
            throw new \InvalidArgumentException('Workerman debug 配置必须是布尔值');
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new \InvalidArgumentException('Workerman debug 只接受 true/false、1/0、yes/no、on/off');
        }

        return $parsed;
    }

    /**
     * 将配置值解析为整数并拒绝非整数输入。
     *
     * @param mixed  $value 配置值
     * @param int    $default 默认值
     * @param string $name 配置项名称
     *
     * @return int
     */
    private function resolveIntegerOption($value, int $default, string $name): int
    {
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value) || !preg_match('/^-?\\d+$/', $value)) {
            throw new \InvalidArgumentException("Workerman {$name} 必须是整数");
        }

        return (int) $value;
    }

    // =========================================================================
    // Getter 方法
    // =========================================================================

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getWorkerCount(): int
    {
        return $this->workerCount;
    }

    public function getMaxRequests(): int
    {
        return $this->maxRequests;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isStaticEnabled(): bool
    {
        return $this->staticEnable;
    }

    public function getStaticPath(): string
    {
        return $this->staticPath;
    }

    public function getLogFile(): string
    {
        return $this->logFile;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    public function getRuntimeDir(): string
    {
        return $this->runtimeDir;
    }

    /**
     * 获取 Worker 监听地址
     *
     * @return string 格式: http://host:port
     */
    public function getListenAddress(): string
    {
        return "http://$this->host:$this->port";
    }

    /**
     * 获取 PID 文件路径
     *
     * @return string
     */
    public function getPidFile(): string
    {
        return $this->runtimeDir . '/workerman.pid';
    }

    /**
     * 获取标准输出日志文件路径
     *
     * @return string
     */
    public function getStdoutFile(): string
    {
        return $this->runtimeDir . '/stdout.log';
    }

    /**
     * 确保运行时目录存在
     *
     * @return void
     */
    public function ensureRuntimeDir(): void
    {
        if (!is_dir($this->runtimeDir)) {
            mkdir($this->runtimeDir, 0755, true);
        }
    }

    /**
     * 获取自定义清理器列表
     *
     * @return array<string>
     */
    public function getCleaners(): array
    {
        return $this->cleaners;
    }

    /**
     * 获取定时任务列表。
     *
     * @return array<string>
     */
    public function getTasks(): array
    {
        return $this->tasks;
    }

}
