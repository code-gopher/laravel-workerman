<?php

declare(strict_types=1);

namespace CodeGopher\LaravelWorkerman;

use Throwable;
use Workerman\Worker;
use Workerman\Timer;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Connection\TcpConnection;
use CodeGopher\LaravelWorkerman\Config\WorkermanConfig;

/**
 * Workerman 服务器管理类
 *
 * 封装 Worker 的创建、配置和生命周期管理
 * 提供清晰的回调注册和服务器启动接口
 * 同时支持 Laravel 和 Lumen 框架
 */
final class WorkermanServer
{
    /** @var WorkermanConfig 配置对象 */
    private $config;

    /** @var Worker|null Worker 实例 */
    private $worker = null;

    /** @var AppManager|null 应用管理器 (每个 Worker 进程独立，支持 Laravel/Lumen) */
    private $appManager = null;

    /** @var bool 当前 Worker 应用初始化失败，不接受动态请求 */
    private $initializationFailed = false;

    /** @var bool 当前 HTTP Worker 是否正在排空已有响应 */
    private $draining = false;

    /** @var float 服务器启动时间 */
    private $serverStartTime;

    /** @var string 健康检查端点路径 */
    private const HEALTH_CHECK_PATH = '/health';

    /** @var string 状态端点路径 */
    private const STATUS_PATH = '/_status';

    /**
     * 构造函数
     *
     * @param WorkermanConfig $config 配置对象
     */
    public function __construct(WorkermanConfig $config)
    {
        $this->config          = $config;
        $this->serverStartTime = microtime(true);
    }

    /**
     * 初始化并运行服务器
     *
     * @return void
     */
    public function run(): void
    {
        $this->setupEnvironment();
        $this->createWorker();
        $this->registerCallbacks();
        $this->createTaskWorker();

        Worker::runAll();
    }

    /**
     * 设置 Worker 运行环境
     *
     * @return void
     */
    private function setupEnvironment(): void
    {
        // 确保运行时目录存在
        $this->config->ensureRuntimeDir();

        // 设置 Workerman 全局配置
        Worker::$pidFile    = $this->config->getPidFile();
        Worker::$logFile    = $this->config->getLogFile();
        Worker::$stdoutFile = $this->config->getStdoutFile();

        // 初始化 Logger（设置调试模式和日志文件）
        Logger::init(
            $this->config->isDebug(),
            $this->config->getLogFile(),
            $this->config->isDebug() ? Logger::LEVEL_DEBUG : Logger::LEVEL_INFO
        );
    }

    /**
     * 创建 Worker 实例
     *
     * @return void
     */
    private function createWorker(): void
    {
        $this->worker        = new Worker($this->config->getListenAddress());
        $this->worker->count = $this->config->getWorkerCount();
        $this->worker->name  = $this->config->getName();
    }

    /**
     * 注册 Worker 回调函数
     *
     * @return void
     */
    private function registerCallbacks(): void
    {
        $this->worker->onWorkerStart = function (Worker $worker) {
            $this->onWorkerStart($worker);
        };
        $this->worker->onMessage     = function (TcpConnection $conn, Request $req) {
            try {
                $this->onMessage($conn, $req);
            } catch (Throwable $exception) {
                // 请求级解析或发送错误不能逃逸到 Workerman 并杀死整个 Worker。
                Logger::error('HTTP 请求处理失败: ' . $exception->getMessage());
                $this->sendResponse($conn, $req, new Response(500, ['Connection' => 'close'], 'Internal Server Error'));
                $conn->close();
            }
        };
        $this->worker->onWorkerStop  = function (Worker $worker) {
            $this->onWorkerStop($worker);
        };
    }

    /**
     * Worker 启动回调
     *
     * 在每个 Worker 进程启动时调用，负责初始化应用（支持 Laravel/Lumen）
     *
     * @param Worker $worker Worker 实例
     *
     * @return void
     */
    private function onWorkerStart(Worker $worker): void
    {
        $this->appManager = null;
        $this->initializationFailed = false;
        $this->appManager = new AppManager(
            $this->config->getBasePath(),
            $this->config->getMaxRequests(),
            $this->config->getPort(),
            $this->config->isDebug(),
            $this->config->getCleaners()
        );

        // 清理 opcode/APCu 失败不应阻止应用启动，部分环境未加载扩展也应正常工作。
        try {
            if (function_exists('opcache_reset')) {
                opcache_reset();
            }
            if (function_exists('apc_clear_cache')) {
                apc_clear_cache();
            }
        } catch (Throwable $e) {
            Logger::warning('启动缓存清理失败: ' . $e->getMessage());
        }

        try {
            $startTime = microtime(true);

            $this->appManager->initialize();

            $initTime   = round((microtime(true) - $startTime) * 1000, 2);
            $memoryUsed = round(memory_get_usage(true) / 1024 / 1024, 2);

            Logger::info(sprintf(
                'Worker #%d 启动成功 | 初始化: %.2fms | 内存: %sMB',
                $worker->id,
                $initTime,
                $memoryUsed
            ));
        } catch (Throwable $e) {
            $this->handleInitializationError($e);
        }
    }

    /**
     * HTTP 请求回调
     *
     * 处理每个 HTTP 请求，支持健康检查、静态文件和动态请求
     *
     * @param TcpConnection $connection TCP 连接对象
     * @param Request       $request    HTTP 请求对象
     *
     * @return void
     */
    private function onMessage(TcpConnection $connection, Request $request): void
    {
        $path = parse_url($request->uri(), PHP_URL_PATH);
        if (!is_string($path)) {
            $this->sendResponse($connection, $request, new Response(400, ['Connection' => 'close'], 'Bad Request'));
            $connection->close();
            return;
        }

        // 健康端点始终可探测，即使应用初始化失败也通过 503 反映不可用状态。
        if ($path === self::HEALTH_CHECK_PATH && ($this->initializationFailed || $this->appManager === null)) {
            $this->sendResponse($connection, $request, new Response(503, ['Content-Type' => 'text/plain; charset=utf-8'], 'Service Unavailable'));
            return;
        }

        // 初始化失败后保留进程并持续返回 503，避免 master 自动重启造成快速循环。
        if ($this->initializationFailed || $this->appManager === null) {
            $this->sendResponse($connection, $request, new Response(503, ['Content-Type' => 'text/plain; charset=utf-8'], 'Service Unavailable'));
            return;
        }

        if ($this->draining) {
            $this->sendResponse($connection, $request, new Response(503, ['Content-Type' => 'text/plain; charset=utf-8', 'Connection' => 'close'], 'Worker is draining'));
            return;
        }

        // 排空中的 Worker 不再宣告健康，便于负载均衡器停止转发。
        if ($path === self::HEALTH_CHECK_PATH) {
            $this->sendResponse($connection, $request, $this->handleHealthCheck());
            return;
        }

        // 状态端点（仅调试模式可用）
        if ($path === self::STATUS_PATH && $this->config->isDebug()) {
            $this->sendResponse($connection, $request, $this->handleStatus());
            return;
        }

        // 尝试处理静态文件
        if ($this->config->isStaticEnabled()) {
            $staticResponse = StaticFileHandler::handle($request, $this->config->getStaticPath());
            if ($staticResponse !== null) {
                $this->sendResponse($connection, $request, $staticResponse);
                return;
            }
        }

        // 应用处理动态请求 (Laravel/Lumen)
        $response = $this->appManager->handleRequest($request);
        $this->sendResponse($connection, $request, $response);

        if ($this->appManager->shouldStopAfterResponse()) {
            $this->beginDrain();
        }
    }

    /**
     * 统一响应出口，确保内置端点与异常路径也遵守 HEAD 语义。
     *
     * @param TcpConnection $connection 当前连接
     * @param Request $request 当前请求
     * @param Response $response 待发送响应
     * @return void
     */
    private function sendResponse(TcpConnection $connection, Request $request, Response $response): void
    {
        if (strtoupper($request->method()) === 'HEAD' && !($response instanceof HeadResponse)) {
            $headers = $response->getHeaders();
            if (!in_array($response->getStatusCode(), [204, 304], true)) {
                $hasLength = false;
                foreach ($headers as $name => $value) {
                    $hasLength = $hasLength || strcasecmp((string) $name, 'Content-Length') === 0;
                }
                if (!$hasLength) {
                    $headers['Content-Length'] = (string) strlen($response->rawBody());
                }
            }
            $response = new HeadResponse($response->getStatusCode(), $headers);
        }
        // 文件发送由 Workerman 分块续写；暂停解析同连接后续请求，防止 HTTP pipeline 响应插入文件正文。
        $sendingFile = isset($response->file);
        if ($sendingFile) {
            $connection->pauseRecv();
        }
        $connection->send($response);
        if ($sendingFile) {
            $timer = null;
            $timer = Timer::add(0.01, function () use ($connection, &$timer): void {
                if ($connection->getStatus() === TcpConnection::STATUS_CLOSED) {
                    Timer::del($timer);
                    return;
                }
                if ($connection->onBufferDrain === null && $connection->bufferIsEmpty()) {
                    Timer::del($timer);
                    $connection->resumeRecv();
                }
            });
        }
    }

    /**
     * 停止接入新连接，等待整个 Worker 的缓冲区和文件发送器结束。
     *
     * @return void
     */
    private function beginDrain(): void
    {
        if ($this->draining || $this->worker === null) {
            return;
        }
        $this->draining = true;
        $this->worker->pauseAccept();
        $deadline = microtime(true) + 30;
        // 不侵入文件 onBufferDrain 链；关闭连接会自然从 connections 移除，避免断连丢通知。
        $timer = null;
        $timer = Timer::add(0.01, function () use (&$timer, $deadline): void {
            $pending = false;
            foreach ($this->worker->connections as $connection) {
                if (!$connection->bufferIsEmpty() || $connection->onBufferDrain !== null) {
                    $pending = true;
                    break;
                }
            }
            if (!$pending || microtime(true) >= $deadline) {
                Timer::del($timer);
                if ($pending) {
                    Logger::warning('Worker 排空超过 30 秒，将关闭剩余连接');
                }
                $this->appManager->stopAfterResponseSent();
            }
        });
    }

    /**
     * 将调度器隔离到独立进程，避免 HTTP 轮换重置定时器或耗时任务阻塞请求。
     *
     * @return void
     */
    private function createTaskWorker(): void
    {
        if ($this->config->getTasks() === []) {
            return;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            throw new \RuntimeException('定时任务独立进程需要 Linux/macOS，请在 Linux Docker 中运行');
        }
        $taskWorker = new Worker();
        $taskWorker->name = $this->config->getName() . '-tasks';
        $taskWorker->count = 1;
        $taskWorker->onWorkerStart = function (): void {
            // 任务进程也需要完成框架引导，但不绑定 HTTP 请求轮换。
            $manager = new AppManager($this->config->getBasePath(), 0, $this->config->getPort(), $this->config->isDebug(), $this->config->getCleaners());
            $manager->initialize();
            $tasks = new TaskManager();
            $tasks->loadTasks($this->config->getTasks());
            $tasks->register();
        };
    }

    /**
     * 处理健康检查请求
     *
     * 返回服务器健康状态，供负载均衡器和监控系统使用
     *
     * @return Response
     */
    private function handleHealthCheck(): Response
    {
        $uptime      = round(microtime(true) - $this->serverStartTime, 2);
        $memoryUsage = round(memory_get_usage(true) / 1024 / 1024, 2);
        $memoryPeak  = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

        $health = [
            'status'    => 'healthy',
            'timestamp' => date('c'),
            'uptime'    => $uptime,
            'memory'    => [
                'current_mb' => $memoryUsage,
                'peak_mb'    => $memoryPeak,
            ],
            'worker'    => [
                'pid'      => getmypid(),
                'requests' => $this->appManager !== null ? $this->appManager->getRequestCount() : 0,
            ],
        ];

        $response = new Response(200);
        $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        $response->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->withBody(json_encode($health, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $response;
    }

    /**
     * 处理状态请求（仅调试模式）
     *
     * 返回详细的服务器状态信息
     *
     * @return Response
     */
    private function handleStatus(): Response
    {
        $stats  = $this->appManager !== null ? $this->appManager->getStats() : [];
        $uptime = round(microtime(true) - $this->serverStartTime, 2);

        $status = [
            'status'    => 'ok',
            'timestamp' => date('c'),
            'uptime'    => $uptime,
            'config'    => [
                'host'         => $this->config->getHost(),
                'port'         => $this->config->getPort(),
                'workers'      => $this->config->getWorkerCount(),
                'max_requests' => $this->config->getMaxRequests(),
                'debug'        => $this->config->isDebug(),
            ],
            'memory'    => [
                'current_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'peak_mb'    => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ],
            'stats'     => [
                'total_requests' => $stats['total_requests'] ?? 0,
                'avg_time_ms'    => $this->calculateAverageTime($stats),
                'min_time_ms'    => $stats['min_time_ms'] ?? 0,
                'max_time_ms'    => $stats['max_time_ms'] ?? 0,
            ],
            'worker'    => [
                'pid'       => getmypid(),
                'framework' => $this->appManager !== null ? $this->appManager->getFrameworkType() : 'unknown',
            ],
            'php'       => [
                'version'    => PHP_VERSION,
                'extensions' => get_loaded_extensions(),
            ],
        ];

        $response = new Response(200);
        $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        $response->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->withBody(json_encode($status, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $response;
    }

    /**
     * Worker 停止回调
     *
     * 在 Worker 进程停止时调用，输出统计信息
     *
     * @param Worker $worker Worker 实例
     *
     * @return void
     */
    private function onWorkerStop(Worker $worker): void
    {
        Timer::delAll();

        // 清理静态文件处理器缓存
        StaticFileHandler::clearCache();

        if ($this->appManager === null || $this->appManager->getRequestCount() === 0) {
            return;
        }

        $stats      = $this->appManager->getStats();
        $avgTime    = $this->calculateAverageTime($stats);
        $peakMemory = $this->appManager->getPeakMemory();

        Logger::info(sprintf(
            'Worker #%d 停止 | 请求数: %d | 平均响应: %.2fms | 峰值内存: %sMB',
            $worker->id,
            $stats['total_requests'],
            $avgTime,
            $peakMemory
        ));
    }

    /**
     * 处理初始化错误
     *
     * @param Throwable $e 异常对象
     *
     * @return void
     */
    private function handleInitializationError(Throwable $e): void
    {
        $this->initializationFailed = true;
        $this->appManager = null;
        Logger::error("初始化失败: {$e->getMessage()}");

        if ($this->config->isDebug()) {
            Logger::error($e->getTraceAsString());
        }

        // 保留失败进程提供 503，不绕过 Workerman 原生退避形成高速重启循环。
    }

    /**
     * 计算平均响应时间
     *
     * @param array<string, mixed> $stats 统计数据
     *
     * @return float
     */
    private function calculateAverageTime(array $stats): float
    {
        $totalRequests = max(1, $stats['total_requests'] ?? 0);
        $totalTime     = $stats['total_time_ms'] ?? 0;

        return round($totalTime / $totalRequests, 2);
    }
}
