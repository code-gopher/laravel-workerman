<?php

declare(strict_types=1);

namespace CodeGopher\LaravelWorkerman\Tests;

use CodeGopher\LaravelWorkerman\Config\WorkermanConfig;
use CodeGopher\LaravelWorkerman\StaticFileHandler;
use CodeGopher\LaravelWorkerman\Cleaners\GlobalVariableCleaner;
use CodeGopher\LaravelWorkerman\AppManager;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;
use ReflectionMethod;

/**
 * 配置解析与静态路径边界的回归测试。
 */
final class WorkermanConfigTest extends TestCase
{
    /**
     * 验证字符串 false 不会误开启调试模式。
     *
     * @return void
     */
    public function testDebugFalseEnvironmentValueRemainsDisabled(): void
    {
        $previousValue = getenv('WORKERMAN_DEBUG');
        putenv('WORKERMAN_DEBUG=false');
        try {
            $config = (new WorkermanConfig(sys_get_temp_dir()))->load();
            self::assertFalse($config->isDebug());
        } finally {
            self::restoreEnvironmentValue('WORKERMAN_DEBUG', $previousValue);
        }
    }

    /**
     * 验证布尔真值可从环境变量解析。
     *
     * @return void
     */
    public function testDebugTrueEnvironmentValueEnablesDebug(): void
    {
        $previousValue = getenv('WORKERMAN_DEBUG');
        putenv('WORKERMAN_DEBUG=true');
        try {
            $config = (new WorkermanConfig(sys_get_temp_dir()))->load();
            self::assertTrue($config->isDebug());
        } finally {
            self::restoreEnvironmentValue('WORKERMAN_DEBUG', $previousValue);
        }
    }

    /**
     * 验证端口无效时立即拒绝配置。
     *
     * @return void
     */
    public function testInvalidPortIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new WorkermanConfig(sys_get_temp_dir()))->load(['port' => 'not-a-port']);
    }

    /**
     * 验证零请求上限关闭自动 Worker 轮换。
     *
     * @return void
     */
    public function testZeroMaxRequestsDisablesWorkerRotation(): void
    {
        $config = (new WorkermanConfig(sys_get_temp_dir()))->load(['max' => '0']);

        self::assertSame(0, $config->getMaxRequests());
    }

    /**
     * 验证非法布尔值不会被静默解释为关闭调试。
     *
     * @return void
     */
    public function testInvalidDebugValueIsRejected(): void
    {
        $previousValue = getenv('WORKERMAN_DEBUG');
        putenv('WORKERMAN_DEBUG=sometimes');
        try {
            $this->expectException(InvalidArgumentException::class);
            (new WorkermanConfig(sys_get_temp_dir()))->load();
        } finally {
            self::restoreEnvironmentValue('WORKERMAN_DEBUG', $previousValue);
        }
    }

    /**
     * 验证环境变量在测试结束后恢复原值。
     *
     * @param string      $name 环境变量名
     * @param string|false $value 原始环境变量值
     *
     * @return void
     */
    private static function restoreEnvironmentValue(string $name, $value): void
    {
        if ($value === false) {
            putenv($name);
            return;
        }

        putenv($name . '=' . $value);
    }

    /**
     * 验证请求全局变量清理不保留前一请求的 HTTP 数据。
     *
     * @return void
     */
    public function testGlobalCleanerRemovesRequestSpecificServerAndCookieData(): void
    {
        $_GET = ['secret' => 'one'];
        $_POST = ['payload' => 'one'];
        $_REQUEST = ['payload' => 'one'];
        $_FILES = ['upload' => 'one'];
        $_COOKIE = ['session' => 'one'];
        $_SERVER = ['HTTP_AUTHORIZATION' => 'Bearer one', 'argv' => ['worker.php']];

        (new GlobalVariableCleaner())->clean(new \stdClass());

        self::assertSame([], $_GET);
        self::assertSame([], $_POST);
        self::assertSame([], $_REQUEST);
        self::assertSame([], $_FILES);
        self::assertSame([], $_COOKIE);
        self::assertSame(['argv' => ['worker.php']], $_SERVER);
    }

    /**
     * 验证 head 请求下普通响应正确生成内容长度。
     *
     * @return void
     */
    public function testHeadResponseUsesStringContentLength(): void
    {
        $manager = (new \ReflectionClass(AppManager::class))->newInstanceWithoutConstructor();
        $converter = new ReflectionMethod(AppManager::class, 'convertResponse');
        if (PHP_VERSION_ID < 80100) {
            $converter->setAccessible(true);
        }
        $request = new \Workerman\Protocols\Http\Request("HEAD / HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $response = new \Symfony\Component\HttpFoundation\Response('response-body');

        $workermanResponse = $converter->invoke($manager, $response, $request);
        $headers = (new \ReflectionClass($workermanResponse))->getProperty('headers');

        self::assertSame('13', $headers->getValue($workermanResponse)['Content-Length']);
    }

    /**
     * 验证 HEAD 响应线协议仅包含一次实体长度且不包含正文。
     *
     * @return void
     */
    public function testHeadResponseWireFormatHasOneContentLengthAndNoBody(): void
    {
        $response = new \CodeGopher\LaravelWorkerman\HeadResponse(200, ['Content-Length' => '9']);
        $serialized = (string) $response;
        $headEnd = strpos($serialized, "\r\n\r\n");

        self::assertSame(1, substr_count($serialized, 'Content-Length:'));
        self::assertStringContainsString("Content-Length: 9\r\n", $serialized);
        self::assertSame('', substr($serialized, $headEnd + 4));
    }

    /**
     * 验证 204 空响应的 HEAD 不伪造实体长度。
     *
     * @return void
     */
    public function testHeadEmptyStatusDoesNotInventContentLength(): void
    {
        $manager = (new \ReflectionClass(AppManager::class))->newInstanceWithoutConstructor();
        $converter = new ReflectionMethod(AppManager::class, 'convertResponse');
        if (PHP_VERSION_ID < 80100) {
            $converter->setAccessible(true);
        }
        $request = new \Workerman\Protocols\Http\Request("HEAD /empty HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $response = new \Symfony\Component\HttpFoundation\Response('', 204);

        $workermanResponse = $converter->invoke($manager, $response, $request);
        self::assertArrayNotHasKey('Content-Length', $workermanResponse->getHeaders());
    }

    /**
     * 验证普通响应多个 Set-Cookie 头转换为字符串。
     *
     * @return void
     */
    public function testResponseCookiesAreSerializedAsSetCookieHeaders(): void
    {
        $manager = (new \ReflectionClass(AppManager::class))->newInstanceWithoutConstructor();
        $converter = new ReflectionMethod(AppManager::class, 'convertResponse');
        if (PHP_VERSION_ID < 80100) {
            $converter->setAccessible(true);
        }
        $request = new \Workerman\Protocols\Http\Request("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $response = new \Symfony\Component\HttpFoundation\Response('ok');
        $response->headers->setCookie(new \Symfony\Component\HttpFoundation\Cookie('session', 'abc', time() + 3600, '/', null, true, true, false, 'lax'));

        $workermanResponse = $converter->invoke($manager, $response, $request);
        $headers = $workermanResponse->getHeaders();

        self::assertSame(1, count($headers['Set-Cookie']));
        self::assertStringStartsWith('session=abc', $headers['Set-Cookie'][0]);
    }

    /**
     * 验证 Workerman 文件响应下 HEAD 元数据保留文件大小。
     *
     * @return void
     */
    public function testHeadBinaryFileResponsePreservesFileSize(): void
    {
        $manager = (new \ReflectionClass(AppManager::class))->newInstanceWithoutConstructor();
        $converter = new ReflectionMethod(AppManager::class, 'convertResponse');
        if (PHP_VERSION_ID < 80100) {
            $converter->setAccessible(true);
        }
        $filePath = tempnam(sys_get_temp_dir(), 'workerman-head-');
        file_put_contents($filePath, 'download-content');

        try {
            $request = new \Workerman\Protocols\Http\Request("HEAD /file HTTP/1.1\r\nHost: localhost\r\n\r\n");
            $response = new \Symfony\Component\HttpFoundation\BinaryFileResponse($filePath);
            $response->headers->set('Content-Length', '16');
            $workermanResponse = $converter->invoke($manager, $response, $request);
            $headers = (new \ReflectionClass($workermanResponse))->getProperty('headers');

            self::assertSame(['16'], $headers->getValue($workermanResponse)['Content-Length']);
            self::assertNull($workermanResponse->file);
        } finally {
            unlink($filePath);
        }
    }

    /**
     * 验证 Workerman 文件响应的 Range 起止位置与字节数。
     *
     * @return void
     */
    public function testBinaryFileRangeIsMappedToWorkermanFileSegment(): void
    {
        $manager = (new \ReflectionClass(AppManager::class))->newInstanceWithoutConstructor();
        $converter = new ReflectionMethod(AppManager::class, 'convertResponse');
        if (PHP_VERSION_ID < 80100) {
            $converter->setAccessible(true);
        }
        $filePath = tempnam(sys_get_temp_dir(), 'workerman-range-');
        file_put_contents($filePath, '0123456789');

        try {
            $request = new \Workerman\Protocols\Http\Request("GET /file HTTP/1.1\r\nHost: localhost\r\nRange: bytes=2-5\r\n\r\n");
            $response = new \Symfony\Component\HttpFoundation\BinaryFileResponse($filePath);
            $response->setStatusCode(206);
            $response->headers->set('Content-Range', 'bytes 2-5/10');
            $response->headers->set('Content-Length', '4');

            $workermanResponse = $converter->invoke($manager, $response, $request);
            $file = (new \ReflectionClass($workermanResponse))->getProperty('file');
            if (PHP_VERSION_ID < 80100) {
                $file->setAccessible(true);
            }
            $fileOptions = $file->getValue($workermanResponse);

            self::assertSame(2, $fileOptions['offset']);
            self::assertSame(4, $fileOptions['length']);
        } finally {
            unlink($filePath);
        }
    }

    /**
     * 验证 Worker 轮换请求达到阈值后设置退出标记。
     *
     * @return void
     */
    public function testRequestLimitSchedulesWorkerRotation(): void
    {
        $manager = (new \ReflectionClass(AppManager::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(AppManager::class, 'maxRequests');
        $count = new \ReflectionProperty(AppManager::class, 'requestCount');
        $cleanup = new ReflectionMethod(AppManager::class, 'cleanupRequest');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
            $count->setAccessible(true);
            $cleanup->setAccessible(true);
        }
        $property->setValue($manager, 1);
        $count->setValue($manager, 1);

        try {
            $cleanup->invoke($manager);
        } catch (\Error $error) {
            // 无容器的单元对象会在清理器调用时失败，但 finally 仍必须设置退出标记。
        }

        self::assertTrue($manager->shouldStopAfterResponse());
    }

    /**
     * 验证二进制响应的 304 和 416 不附加文件正文。
     *
     * @return void
     */
    public function testBinaryFileConditionalResponsesDoNotSendFileBody(): void
    {
        $manager = (new \ReflectionClass(AppManager::class))->newInstanceWithoutConstructor();
        $converter = new ReflectionMethod(AppManager::class, 'convertResponse');
        if (PHP_VERSION_ID < 80100) {
            $converter->setAccessible(true);
        }
        $filePath = tempnam(sys_get_temp_dir(), 'workerman-status-');
        file_put_contents($filePath, 'content');

        try {
            foreach ([304, 416] as $statusCode) {
                $request = new \Workerman\Protocols\Http\Request("GET /file HTTP/1.1\r\nHost: localhost\r\n\r\n");
                $response = new \Symfony\Component\HttpFoundation\BinaryFileResponse($filePath);
                $response->setStatusCode($statusCode);
                $workermanResponse = $converter->invoke($manager, $response, $request);

                self::assertSame($statusCode, $workermanResponse->getStatusCode());
                self::assertNull($workermanResponse->file);
            }
        } finally {
            unlink($filePath);
        }
    }

    /**
     * 验证 BinaryFileResponse 的 416 不保留与空实体冲突的 Content-Length。
     *
     * @return void
     */
    public function testBinaryFileUnsatisfiableRangeDoesNotKeepEntityLength(): void
    {
        $manager = (new \ReflectionClass(AppManager::class))->newInstanceWithoutConstructor();
        $converter = new ReflectionMethod(AppManager::class, 'convertResponse');
        if (PHP_VERSION_ID < 80100) {
            $converter->setAccessible(true);
        }
        $filePath = tempnam(sys_get_temp_dir(), 'workerman-range-416-');
        file_put_contents($filePath, '0123456789');

        try {
            $request = new \Workerman\Protocols\Http\Request("GET /file HTTP/1.1\r\nHost: localhost\r\nRange: bytes=99-100\r\n\r\n");
            $response = new \Symfony\Component\HttpFoundation\BinaryFileResponse($filePath);
            $response->setStatusCode(416);
            $response->headers->set('Content-Length', '10');
            $response->headers->set('Content-Range', 'bytes */10');

            $workermanResponse = $converter->invoke($manager, $response, $request);
            $serialized = (string) $workermanResponse;
            $headerBlock = substr($serialized, 0, strpos($serialized, "\r\n\r\n"));

            self::assertSame(416, $workermanResponse->getStatusCode());
            self::assertSame(1, substr_count($headerBlock, 'Content-Length:'));
            self::assertStringContainsString('Content-Length: 0', $headerBlock);
            self::assertStringContainsString('Content-Range: bytes */10', $headerBlock);
            self::assertNull($workermanResponse->file);
        } finally {
            unlink($filePath);
        }
    }

    /**
     * 验证静态目录的共享前缀不会通过目录边界检查。
     *
     * @return void
     */
    public function testSiblingPrefixIsOutsideStaticDirectory(): void
    {
        $method = new ReflectionMethod(StaticFileHandler::class, 'isPathWithinDirectory');
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        self::assertFalse($method->invoke(null, '/srv/app/public-private/file.css', '/srv/app/public'));
        self::assertTrue($method->invoke(null, '/srv/app/public/assets/file.css', '/srv/app/public'));
    }
}
