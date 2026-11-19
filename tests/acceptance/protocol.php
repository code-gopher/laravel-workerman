<?php

declare(strict_types=1);

require_once __DIR__ . '/http.php';

// HEAD 与 GET 复用同一连接，若 HEAD 泄漏正文，后续 JSON 解析会直接失败。
$baseUrl = getenv('ACCEPTANCE_BASE_URL') ?: 'http://127.0.0.1:18766';
$handle = curl_init();
foreach (['/health', '/ready'] as $path) {
    curl_setopt($handle, CURLOPT_NOBODY, true);
    fetchResponse($handle, $baseUrl . $path);
    curl_setopt($handle, CURLOPT_NOBODY, false);
    $health = json_decode(fetchResponse($handle, $baseUrl . '/health'), true, 512, JSON_THROW_ON_ERROR);
    ensure($health['status'] === 'healthy', 'HEAD contaminated keep-alive connection');
}
fetchResponse($handle, $baseUrl . '//example:bad/a.css', [], 400);
fetchResponse($handle, $baseUrl . '/ready');
echo "PASS protocol: HEAD keep-alive and malformed URI recovery\n";
