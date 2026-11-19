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
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $property->setValue($manager, 1);
        $count = new \ReflectionProperty(AppManager::class, 'requestCount');
        if (PHP_VERSION_ID < 80100) {
            $count->setAccessible(true);
        }
        $count->setValue($manager, 1);
        $cleanup = new ReflectionMethod(AppManager::class, 'cleanupRequest');
        if (PHP_VERSION_ID < 80100) {
            $cleanup->setAccessible(true);
        }

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
