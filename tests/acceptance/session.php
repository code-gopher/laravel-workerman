<?php

declare(strict_types=1);

// 复用 cURL 验证助手；此脚本只做独立客户端会话验收。
require_once __DIR__ . '/http.php';

$baseUrl = getenv('ACCEPTANCE_BASE_URL') ?: 'http://127.0.0.1:18766';
$first = curl_init();
$second = curl_init();
curl_setopt($first, CURLOPT_COOKIEFILE, '');
curl_setopt($second, CURLOPT_COOKIEFILE, '');
fetchResponse($first, $baseUrl . '/acceptance/session?value=client-a');
fetchResponse($second, $baseUrl . '/acceptance/session?value=client-b');
for ($index = 0; $index < 30; $index++) {
    $a = json_decode(fetchResponse($first, $baseUrl . '/acceptance/session'), true, 512, JSON_THROW_ON_ERROR);
    $b = json_decode(fetchResponse($second, $baseUrl . '/acceptance/session'), true, 512, JSON_THROW_ON_ERROR);
    ensure($a['value'] === 'client-a' && $b['value'] === 'client-b', 'Session identity leaked across clients');
}
fetchResponse($first, $baseUrl . '/acceptance/session?flash=one-request');
$flash = json_decode(fetchResponse($first, $baseUrl . '/acceptance/session'), true, 512, JSON_THROW_ON_ERROR);
ensure($flash['notice'] === 'one-request', 'Flash disappeared before next request');
$expired = json_decode(fetchResponse($first, $baseUrl . '/acceptance/session'), true, 512, JSON_THROW_ON_ERROR);
ensure($expired['notice'] === null, 'Flash survived more than one request');
echo "PASS session: independent cookie jars, 60 alternating reads, persisted file storage, flash lifecycle\n";
