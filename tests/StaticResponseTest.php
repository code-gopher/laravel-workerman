<?php

declare(strict_types=1);

namespace CodeGopher\LaravelWorkerman\Tests;

use CodeGopher\LaravelWorkerman\HeadResponse;
use CodeGopher\LaravelWorkerman\StaticFileHandler;
use PHPUnit\Framework\TestCase;
use Workerman\Protocols\Http\Request;

/**
 * 覆盖静态请求解析失败与 HEAD 响应的线协议回归。
 */
final class StaticResponseTest extends TestCase
{
    /** @var string 临时静态目录，每次测试独立以隔离路径缓存。 */
    private $staticDir;

    /** @var string 用于核对文件字节数的静态内容。 */
    private const FILE_CONTENT = 'body { color: red; }';

    /**
     * 为每个用例创建独立静态目录和正常、空文件。
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->staticDir = sys_get_temp_dir() . '/workerman-static-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->staticDir));
        file_put_contents($this->staticDir . '/asset.css', self::FILE_CONTENT);
        file_put_contents($this->staticDir . '/empty.txt', '');
        StaticFileHandler::clearCache();
    }

    /**
     * 清理测试文件和静态路径缓存，避免影响后续请求。
     *
     * @return void
     */
    protected function tearDown(): void
    {
        StaticFileHandler::clearCache();
        unlink($this->staticDir . '/asset.css');
        unlink($this->staticDir . '/empty.txt');
        rmdir($this->staticDir);
        parent::tearDown();
    }

    /**
     * 非法端口不应把 parse_url 的 false 传给 pathinfo，更不应影响后续请求。
     *
     * @return void
     */
    public function testInvalidPortDoesNotThrowOrPoisonFollowingRequests(): void
    {
        foreach (['GET', 'HEAD'] as $method) {
            foreach (['http://localhost:invalid/asset.css', 'http://localhost:65536/asset.css', '//localhost:invalid/asset.css'] as $uri) {
                self::assertFalse(parse_url($uri, PHP_URL_PATH));
                self::assertNull(StaticFileHandler::handle($this->createRequest($method, $uri), $this->staticDir));
            }
        }

        $response = StaticFileHandler::handle($this->createRequest('HEAD', '/asset.css'), $this->staticDir);
        self::assertInstanceOf(HeadResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * 创建指定方法和 URI 的 Workerman HTTP 请求。
     *
     * @param string $method HTTP 方法
     * @param string $uri 请求 URI
     * @return Request
     */
    private function createRequest(string $method, string $uri): Request
    {
        return new Request($method . ' ' . $uri . " HTTP/1.1\r\nHost: localhost\r\n\r\n");
    }
}
