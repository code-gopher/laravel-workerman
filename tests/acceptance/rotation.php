<?php

declare(strict_types=1);

require_once __DIR__ . '/http.php';

$baseUrl = getenv('ACCEPTANCE_BASE_URL') ?: 'http://127.0.0.1:18769';
$file = dirname(__DIR__) . '/laravel-fixture/fixtures/large.bin';
ensure(is_file($file), 'Large fixture missing');
$expectedHash = hash_file('sha256', $file);
$healthHandle = curl_init();
$health = json_decode(fetchResponse($healthHandle, $baseUrl . '/health'), true, 512, JSON_THROW_ON_ERROR);
$pid = $health['worker']['pid'];
$ticksPath = getenv('ACCEPTANCE_TICKS_PATH') ?: '/tmp/workerman-acceptance-ticks.log';
$beforeTicks = is_file($ticksPath) ? count(file($ticksPath)) : 0;
// 前五次请求中保持大文件连接未读完，再用第六次请求触发整个 Worker 排空。
$multi = curl_multi_init();
$downloads = [];
for ($index = 0; $index < 2; $index++) {
    $handle = curl_init($baseUrl . '/large-download');
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_MAX_RECV_SPEED_LARGE => 4 * 1024 * 1024]);
    curl_multi_add_handle($multi, $handle);
    $downloads[] = $handle;
}
// 先驱动下载，使服务端确实开始发送，而非仅把请求放入客户端队列。