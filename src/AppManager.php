<?php

declare(strict_types=1);

namespace CodeGopher\LaravelWorkerman;

use Throwable;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;
use Illuminate\Support\Facades\Facade;
use Illuminate\Contracts\Debug\ExceptionHandler;
use CodeGopher\LaravelWorkerman\Contracts\FrameworkAdapter;
use CodeGopher\LaravelWorkerman\Adapters\AdapterFactory;

/**
 * 应用管理器
 *
 * 负责在 Workerman 进程中管理 Laravel/Lumen 应用实例
 * 通过适配器模式同时支持 Laravel 和 Lumen 框架
 */
class AppManager
{
    /** @var FrameworkAdapter 框架适配器 */
    private $adapter;

    /** @var CleanerManager 清理器管理器 */
    private $cleanerManager;

    /** @var bool 是否已初始化 */
    private $initialized = false;

    /** @var int 当前进程已处理的请求数 */
    private $requestCount = 0;

    /** @var int 最大请求数 */
    private $maxRequests;

    /** @var bool 是否应在当前响应发送后停止 Worker */
    private $stopAfterResponse = false;

    /** @var float 峰值内存 */
    private $peakMemory = 0;

    /** @var array<string, mixed> 性能统计 */
    private $stats = [
        'total_requests' => 0,
        'total_time_ms'  => 0,
        'min_time_ms'    => PHP_FLOAT_MAX,
        'max_time_ms'    => 0,
    ];

    /** @var string 基础路径 */
    private $basePath;

    /** @var int 监听端口 */
    private $port;

    /** @var bool 是否开启调试模式 */
    private $debug;

    /** @var array<string> 自定义清理器类名列表 */
    private $cleaners;

    /**
     * @param string        $basePath    Laravel/Lumen 项目根目录
     * @param int           $maxRequests 最大请求数
     * @param int           $port        监听端口
     * @param bool          $debug       调试模式
     * @param array<string> $cleaners    自定义清理器类名列表
     */
    public function __construct(
        string $basePath,
        int    $maxRequests = 10000,
        int    $port = 8080,
        bool   $debug = false,
        array  $cleaners = []
    ) {
        $this->basePath    = $basePath;
        $this->maxRequests = PHP_OS_FAMILY === 'Windows' ? 0 : $maxRequests;
        $this->port        = $port;
        $this->debug       = $debug || filter_var(getenv('WORKERMAN_DEBUG'), FILTER_VALIDATE_BOOLEAN);
        $this->cleaners    = $cleaners;
    }

    /**
     * 初始化应用（只执行一次）
     *
     * @return void
     */
    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        // 加载应用实例
        $app = require $this->basePath . '/bootstrap/app.php';

        // 使用工厂创建适配器
        $this->adapter = AdapterFactory::create($app);

        // 引导应用
        $this->bootstrapApp();

        // 初始化清理器管理器
        $this->cleanerManager = new CleanerManager($app);

        // 加载用户自定义清理器
        $this->cleanerManager->loadCleaners($this->cleaners);

        $this->initialized = true;

        Logger::info(sprintf(
            '框架类型: %s | Workerman 服务已启动',
            strtoupper($this->adapter->getFrameworkType())
        ));
    }

    /**
     * 引导应用
     *
     * @return void
     */
    private function bootstrapApp(): void
    {
        $mockRequest = \Illuminate\Http\Request::create(
            'http://localhost/',
            'GET',
            [],
            [],
            [],
            [
                'HTTP_HOST'   => 'localhost',
                'SERVER_NAME' => 'localhost',
                'SERVER_PORT' => $this->port,
                'SCRIPT_NAME' => '/index.php',
                'REQUEST_URI' => '/',
            ]
        );

        $this->adapter->bootstrap($mockRequest);

        // 清理 Facade 缓存（如果启用了 Facade）
        if (class_exists(Facade::class)) {
            Facade::clearResolvedInstance('request');
        }
    }

    /**
     * 处理 HTTP 请求
     *
     * @param Request $workermanRequest Workerman 请求对象
     *
     * @return Response
     */
    public function handleRequest(Request $workermanRequest): Response
    {
        $this->requestCount++;
        $requestStartTime = microtime(true);
        $laravelRequest = null;
        $laravelResponse = null;
        $terminated = false;
        $app = $this->adapter->getApp();
        // 请求内注册的 afterResponse/terminating 回调只能执行一次；提供者基线仍保留。
        $callbacksProperty = new \ReflectionProperty($app, 'terminatingCallbacks');
        if (PHP_VERSION_ID < 80100) {
            $callbacksProperty->setAccessible(true);
        }
        $terminatingCallbacks = $callbacksProperty->getValue($app);
        $config = $app->make('config');
        $defaultGuard = $config->get('auth.defaults.guard');

        try {
            // 调试模式：打印请求信息
            if ($this->debug) {
                $this->logDebugRequest($workermanRequest);
            }

            $laravelRequest = $this->convertRequest($workermanRequest, $requestStartTime);

            // Laravel 6 到 12 的容器绑定都需要同步更新，避免旧 Request 通过类名解析。
            $this->adapter->instance('request', $laravelRequest);
            $this->adapter->instance('Illuminate\\Http\\Request', $laravelRequest);
            $this->adapter->instance('Symfony\\Component\\HttpFoundation\\Request', $laravelRequest);

            // 清理 Facade 缓存（如果启用了 Facade）
            if (class_exists(Facade::class)) {
                Facade::clearResolvedInstance('request');
            }

            $laravelResponse = $this->adapter->handle($laravelRequest);
            $response        = $this->convertResponse($laravelResponse, $workermanRequest);

            $terminated = true;
            try {
                $this->adapter->terminate($laravelRequest, $laravelResponse);
            } catch (Throwable $terminationException) {
                // 终止过程可能只执行了一部分副作用，不能让该 Worker 继续承接请求。
                $this->stopAfterResponse = true;
                throw $terminationException;
            }

            $requestTime = (microtime(true) - $requestStartTime) * 1000;
            $this->updateStats($requestTime);

            // 调试模式：打印响应信息
            if ($this->debug) {
                Logger::debug(sprintf('Response: %d, Time: %.2fms', $laravelResponse->getStatusCode(), $requestTime));
            }

            return $response;

        } catch (Throwable $e) {
            return $this->handleException($e);
        } finally {
            // 响应转换失败也不能跳过框架终止钩子；终止失败不覆盖已有响应。
            try {
                if (!$terminated && $laravelRequest !== null && $laravelResponse !== null) {
                    $this->adapter->terminate($laravelRequest, $laravelResponse);
                }
            } catch (Throwable $terminationException) {
                Logger::error('请求终止失败: ' . $terminationException->getMessage());
                $this->stopAfterResponse = true;
            } finally {
                $callbacksProperty->setValue($app, $terminatingCallbacks);
                $config->set('auth.defaults.guard', $defaultGuard);
            }
            // 无论请求成功或失败，都必须隔离本次请求状态并检查 Worker 请求上限。
            try {
                $this->cleanupRequest();
            } catch (Throwable $cleanupException) {
                // 清理失败不能覆盖当前请求已经生成的响应或异常结果。
                Logger::error('请求清理失败: ' . $cleanupException->getMessage());
                // 无法证明隔离成功时淘汰 Worker，禁止把污染状态交给下一位用户。
                $this->stopAfterResponse = true;
            }
        }
    }

    /**
     * 调试模式：打印请求信息
     *
     * @param Request $workermanRequest
     *
     * @return void
     */
    private function logDebugRequest(Request $workermanRequest): void
    {
        $method = $workermanRequest->method();
        $uri    = $workermanRequest->uri();

        Logger::debug("{$method} {$uri}");

        // 打印 Query 参数
        $query = $workermanRequest->get();
        if (!empty($query)) {
            Logger::debug('Query: ' . json_encode($query, JSON_UNESCAPED_UNICODE));
        }

        // 打印 POST 参数（非文件）
        $post = $workermanRequest->post();
        if (!empty($post)) {
            Logger::debug('POST: ' . json_encode($post, JSON_UNESCAPED_UNICODE));
        }

        // 打印上传文件信息
        $files = $workermanRequest->file();
        if (!empty($files)) {
            $fileInfo = [];
            foreach ($files as $key => $file) {
                if (is_array($file) && isset($file['name'])) {
                    $fileInfo[$key] = $file['name'];
                } elseif (is_object($file) && method_exists($file, 'getClientOriginalName')) {
                    $fileInfo[$key] = $file->getClientOriginalName();
                }
            }
            Logger::debug('Files: ' . json_encode($fileInfo, JSON_UNESCAPED_UNICODE));
        }

        // 打印 JSON Body（如果是 JSON 请求）
        $contentType = $workermanRequest->header('content-type') ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $rawBody = $workermanRequest->rawBody();
            if (!empty($rawBody)) {
                $jsonData = json_decode($rawBody, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    Logger::debug('JSON Body: ' . json_encode($jsonData, JSON_UNESCAPED_UNICODE));
                }
            }
        }
    }

    /**
     * 生成唯一请求 ID
     *
     * 使用加密安全的随机数生成器，确保请求 ID 不可预测
     *
     * @return string
     */
    private function generateRequestId(): string
    {
        try {
            // 使用加密安全的随机字节
            return bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            // 降级方案：如果 random_bytes 不可用
            return sprintf(
                '%s%08x%04x',
                substr(hash('sha256', uniqid((string)mt_rand(), true) . microtime(true)), 0, 16),
                time(),
                getmypid() % 0xffff
            );
        }
    }

    /**
     * 转换 Workerman 请求为 Illuminate 请求
     *
     * @param Request $workermanRequest
     * @param float   $requestStartTime
     *
     * @return \Illuminate\Http\Request
     */
    private function convertRequest(Request $workermanRequest, float $requestStartTime): \Illuminate\Http\Request
    {
        $connection = $workermanRequest->connection;
        $remoteIp   = $connection !== null ? $connection->getRemoteIp() : '127.0.0.1';
        $remotePort = $connection !== null ? $connection->getRemotePort() : 0;

        $_GET = $workermanRequest->get() ?? [];
        $_POST = $workermanRequest->post() ?? [];
        // Workerman 保留 Cookie 原始编码；PHP SAPI 会 URL 解码，Laravel 加密 Cookie 依赖该语义。
        $_COOKIE = array_map('urldecode', $workermanRequest->cookie() ?? []);
        $_FILES = $workermanRequest->file() ?? [];
        $rawBody = $workermanRequest->rawBody();

        $requestServerVariables = [
            'REQUEST_METHOD'     => $workermanRequest->method(),
            'REQUEST_URI'        => $workermanRequest->uri(),
            'QUERY_STRING'       => $workermanRequest->queryString() ?? '',
            'SERVER_PROTOCOL'    => 'HTTP/' . $workermanRequest->protocolVersion(),
            'SERVER_NAME'        => $workermanRequest->host(true) ?? 'localhost',
            'HTTP_HOST'          => $workermanRequest->host() ?? 'localhost',
            'HTTPS'              => 'off',
            'REMOTE_ADDR'         => $remoteIp,
            'REMOTE_PORT'         => $remotePort,
            'SERVER_PORT'         => $this->port,
            'DOCUMENT_ROOT'       => $this->basePath . '/public',
            'SCRIPT_FILENAME'     => $this->basePath . '/public/index.php',
            'SCRIPT_NAME'         => '/index.php',
            'REQUEST_TIME'        => time(),
            'REQUEST_TIME_FLOAT'  => $requestStartTime,
            'REQUEST_ID'          => $this->generateRequestId(),
            'START_TIME'          => $requestStartTime,
            'argv'                => $GLOBALS['argv'] ?? [],
            'argc'                => $GLOBALS['argc'] ?? 0,
        ];

        foreach (array_keys($_SERVER) as $serverKey) {
            if (strpos($serverKey, 'HTTP_') === 0 || strpos($serverKey, 'REQUEST_') === 0 || strpos($serverKey, 'CONTENT_') === 0) {
                unset($_SERVER[$serverKey]);
            }
        }
        $_SERVER = array_merge($_SERVER, $requestServerVariables);

        foreach ($workermanRequest->header() as $name => $value) {
            $name = strtoupper(str_replace('-', '_', $name));
            if ($name === 'CONTENT_TYPE' || $name === 'CONTENT_LENGTH') {
                $_SERVER[$name] = $value;
                continue;
            }

            $_SERVER['HTTP_' . $name] = $value;
        }

        $files = UploadedFileConverter::convert($_FILES);

        $laravelRequest = new \Illuminate\Http\Request($_GET, $_POST, [], $_COOKIE, $files, $_SERVER, $rawBody);
        $laravelRequest->enableHttpMethodParameterOverride();

        return $laravelRequest;
    }


    /**
     * 转换响应为 Workerman 响应
     *
     * @param \Symfony\Component\HttpFoundation\Response $laravelResponse
     * @param Request                                      $workermanRequest
     *
     * @return Response
     */
    private function convertResponse(
        \Symfony\Component\HttpFoundation\Response $laravelResponse,
        Request $workermanRequest
    ): Response
    {
        $headers = method_exists($laravelResponse->headers, 'allPreserveCase')
            ? $laravelResponse->headers->allPreserveCase()
            : $laravelResponse->headers->all();
        // Cookie 由专用路径序列化，先从普通头集合移除，避免同一响应重复发送。
        foreach (array_keys($headers) as $headerName) {
            if (strcasecmp($headerName, 'Set-Cookie') === 0) {
                unset($headers[$headerName]);
            }
        }

        if ($laravelResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
            throw new \RuntimeException('当前 Workerman 适配器不支持 StreamedResponse/SSE，请使用普通响应');
        }

        if ($laravelResponse instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
            $filePath = $laravelResponse->getFile()->getPathname();
            if (!is_file($filePath) || !is_readable($filePath)) {
                throw new \RuntimeException('下载文件不存在或不可读取');
            }

            $isHeadRequest = strtoupper($workermanRequest->method()) === 'HEAD';
            $response = $isHeadRequest
                ? new HeadResponse($laravelResponse->getStatusCode(), $headers)
                : new Response($laravelResponse->getStatusCode(), $headers);
            $this->copyResponseCookies($laravelResponse, $response);
            if ($isHeadRequest) {
                if (!$laravelResponse->isEmpty() && !$laravelResponse->headers->has('Content-Length')) {
                    $response->withHeader('Content-Length', (string) filesize($filePath));
                }
                return $response;
            }

            $statusCode = $laravelResponse->getStatusCode();
            if ($statusCode === 304) {
                // 304 不包含实体，移除传输长度与范围元数据。
                $headers = $this->withoutFileTransferHeaders($headers);
                $headers = $this->withoutContentLengthHeaders($headers);
                $response = new Response($statusCode, $headers);
                $this->copyResponseCookies($laravelResponse, $response);
                return $response;
            }

            if ($statusCode === 416) {
                // 416 必须保留 Content-Range: bytes */size，但不能携带文件长度。
                $headers = $this->withoutContentLengthHeaders($headers);
                $response = new Response($statusCode, $headers);
                $this->copyResponseCookies($laravelResponse, $response);
                return $response;
            }

            if (!$laravelResponse->isSuccessful()) {
                return $response;
            }

            // BinaryFileResponse 的临时文件删除由 Symfony 在 sendContent 后完成，Workerman 不会触发该流程。
            $deleteAfterSendProperty = new \ReflectionProperty($laravelResponse, 'deleteFileAfterSend');
            // PHP 8.1 起反射属性已可直接访问，避免在 PHP 8.5 触发弃用警告。
            if (PHP_VERSION_ID < 80100) {
                $deleteAfterSendProperty->setAccessible(true);
            }
            if ($deleteAfterSendProperty->getValue($laravelResponse)) {
                throw new \RuntimeException('当前适配器不支持发送后删除文件的 BinaryFileResponse');
            }
            // Workerman 的文件响应不支持依赖外部 Web 服务器的 X-Sendfile 卸载。
            if ($laravelResponse->headers->has('X-Sendfile')
                || $laravelResponse->headers->has('X-Accel-Redirect')
            ) {
                throw new \RuntimeException('当前适配器不支持 X-Sendfile 文件卸载');
            }

            if ($statusCode === 206) {
                $contentRange = $laravelResponse->headers->get('Content-Range');
                if (!is_string($contentRange)
                    || !preg_match('/^bytes (\\d+)-(\\d+)\\/(\\d+|\\*)$/', $contentRange, $rangeMatches)
                ) {
                    throw new \RuntimeException('下载文件范围响应头无效');
                }

                $offset = (int) $rangeMatches[1];
                $length = (int) $rangeMatches[2] - $offset + 1;
                if ($length < 1 || $offset + $length > filesize($filePath)) {
                    throw new \RuntimeException('下载文件范围无效');
                }

                $headers = $this->withoutFileTransferHeaders($headers);
                $headers = $this->withoutContentLengthHeaders($headers);
                $response = new Response($statusCode, $headers);
                $this->copyResponseCookies($laravelResponse, $response);
                $response->withFile($filePath, $offset, $length);

                return $response;
            }

            if ($laravelResponse->headers->has('Content-Range')) {
                throw new \RuntimeException('当前适配器不支持多段文件范围响应');
            }

            // Workerman 会依据 withFile() 重新计算文件长度，避免重复输出 Symfony 头。
            $headers = $this->withoutFileTransferHeaders($headers);
            $response = new Response($statusCode, $headers);
            $this->copyResponseCookies($laravelResponse, $response);
            $response->withFile($filePath);
            return $response;
        }

        $content = $laravelResponse->getContent();
        if (!is_string($content)) {
            throw new \RuntimeException('HTTP 响应内容必须是字符串');
        }

        if (strtoupper($workermanRequest->method()) === 'HEAD') {
            $response = new HeadResponse(
                $laravelResponse->getStatusCode(),
                $headers
            );
            if ($laravelResponse->isEmpty()) {
                $response->withoutHeader('Content-Length');
            } elseif (!$laravelResponse->headers->has('Content-Length')) {
                $response->withHeader('Content-Length', (string) strlen($content));
            }

            $this->copyResponseCookies($laravelResponse, $response);
            return $response;
        }

        $response = new Response(
            $laravelResponse->getStatusCode(),
            $headers,
            $content
        );
        $this->copyResponseCookies($laravelResponse, $response);

        return $response;
    }

    /**
     * 将 Symfony 响应头中的 Cookie 对象转换为 Workerman 可发送的字符串。
     *
     * @param \Symfony\Component\HttpFoundation\Response $source 源响应
     * @param Response $target Workerman 目标响应
     *
     * @return void
     */
    private function copyResponseCookies(
        \Symfony\Component\HttpFoundation\Response $source,
        Response $target
    ): void {
        $cookies = [];
        foreach ($source->headers->getCookies() as $cookie) {
            $cookies[] = (string) $cookie;
        }

        if ($cookies !== []) {
            $target->withHeaders(['Set-Cookie' => $cookies]);
        }
    }

    /**
     * 移除 Workerman withFile() 会重新生成的文件传输头。
     *
     * @param array<string, mixed> $headers 源响应头
     *
     * @return array<string, mixed>
     */
    private function withoutFileTransferHeaders(array $headers): array
    {
        foreach (array_keys($headers) as $headerName) {
            if (strcasecmp($headerName, 'Content-Length') === 0
                || strcasecmp($headerName, 'Content-Range') === 0
                || strcasecmp($headerName, 'Accept-Ranges') === 0
            ) {
                unset($headers[$headerName]);
            }
        }

        return $headers;
    }

    /**
     * 删除有正文头集合中的 Content-Length，交由 Workerman 根据实体生成长度。
     *
     * @param array<string, mixed> $headers 响应头集合
     *
     * @return array<string, mixed>
     */
    private function withoutContentLengthHeaders(array $headers): array
    {
        foreach (array_keys($headers) as $headerName) {
            if (strcasecmp($headerName, 'Content-Length') === 0) {
                unset($headers[$headerName]);
            }
        }

        return $headers;
    }

    /**
     * 更新性能统计
     *
     * @param float $requestTimeMs
     *
     * @return void
     */
    private function updateStats(float $requestTimeMs): void
    {
        $this->stats['total_requests']++;
        $this->stats['total_time_ms'] += $requestTimeMs;
        $this->stats['min_time_ms']   = min($this->stats['min_time_ms'], $requestTimeMs);
        $this->stats['max_time_ms']   = max($this->stats['max_time_ms'], $requestTimeMs);

        $currentMemory    = memory_get_usage(true) / 1024 / 1024;
        $this->peakMemory = max($this->peakMemory, $currentMemory);
    }

    /**
     * 清理请求相关资源
     *
     * @return void
     */
    private function cleanupRequest(): void
    {
        try {
            // 先清理请求状态，避免达到轮换阈值时跳过本次请求的资源回收。
            $this->cleanerManager->cleanup($this->adapter->getApp());

            // 定期执行垃圾回收
            if ($this->requestCount % 100 === 0) {
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
                if (function_exists('gc_mem_caches')) {
                    gc_mem_caches();
                }
            }
        } finally {
            // 即使清理器失败，也必须检查请求数上限，防止异常状态永久留在 Worker 中。
            if ($this->maxRequests > 0 && $this->requestCount >= $this->maxRequests) {
                $avgTime = round($this->stats['total_time_ms'] / max(1, $this->stats['total_requests']), 2);
                Logger::info("已处理 {$this->requestCount} 个请求，平均耗时 {$avgTime}ms，峰值内存 {$this->peakMemory}MB");
                Logger::info('达到最大请求数限制，当前请求发送完成后将轮换 Worker 子进程...');
                $this->stopAfterResponse = true;
            }
        }
    }

    /**
     * 处理异常
     *
     * @param Throwable $e
     *
     * @return Response
     */
    private function handleException(Throwable $e): Response
    {
        try {
            $handler = $this->adapter->make(ExceptionHandler::class);
            $handler->report($e);
        } catch (Throwable $reportException) {
            // 忽略
        }

        $response = new Response(500);
        $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        $response->withBody(json_encode([
            'code'    => 500,
            'message' => 'Internal Server Error',
        ], JSON_UNESCAPED_UNICODE));

        if ($this->debug) {
            $response->withHeader('X-Workerman-Error', substr(hash('sha256', $e->getMessage()), 0, 12));
        }

        return $response;
    }

    /**
     * 判断当前请求是否已达到请求数上限。
     *
     * @return bool
     */
    public function shouldStopAfterResponse(): bool
    {
        return $this->stopAfterResponse;
    }

    /**
     * 请求响应发送完成后退出当前 Worker 子进程，由 Workerman 主进程补充新进程。
     *
     * @return void
     */
    public function stopAfterResponseSent(): void
    {
        if (!$this->stopAfterResponse) {
            return;
        }

        $this->stopAfterResponse = false;

        // 子进程只退出自身；Workerman 主进程负责补足该 Worker，Windows 由进程监控重启。
        Worker::stopAll();
    }

    /**
     * 获取统计信息
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * 获取请求计数
     *
     * @return int
     */
    public function getRequestCount(): int
    {
        return $this->requestCount;
    }

    /**
     * 获取峰值内存
     *
     * @return float
     */
    public function getPeakMemory(): float
    {
        return round($this->peakMemory, 2);
    }

    /**
     * 获取框架类型
     *
     * @return string
     */
    public function getFrameworkType(): string
    {
        return $this->adapter->getFrameworkType();
    }
}
