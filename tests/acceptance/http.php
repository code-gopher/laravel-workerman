<?php

declare(strict_types=1);

/**
 * 检查真实 HTTP 响应，任何不一致立即使验收失败。
 *
 * @param bool $condition 断言条件
 * @param string $message 失败说明
 * @return void
 * @throws RuntimeException 当断言不成立时
 */
function ensure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * 通过复用连接发送请求，验证同一 Worker 内连续请求的状态隔离。
 *
 * @param mixed $handle cURL 连接句柄
 * @param string $url 请求地址
 * @param array<string> $headers 请求头
 * @param int $expectedStatus 预期 HTTP 状态
 * @return string
 */
function fetchResponse($handle, string $url, array $headers = [], int $expectedStatus = 200): string
{
    curl_setopt_array($handle, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = curl_exec($handle);
    ensure($body !== false, 'HTTP transport failed: ' . curl_error($handle));
    ensure(curl_getinfo($handle, CURLINFO_HTTP_CODE) === $expectedStatus, 'Unexpected HTTP response: ' . $body);

    return $body;
}

