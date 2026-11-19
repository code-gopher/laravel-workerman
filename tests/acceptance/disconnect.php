<?php

declare(strict_types=1);

require_once __DIR__ . '/http.php';

// 此脚本紧接 rotation.php，在已轮换、动态请求计数归零的 Worker 上运行。
$baseUrl = getenv('ACCEPTANCE_BASE_URL') ?: 'http://127.0.0.1:18769';
$handle = curl_init();
$before = json_decode(fetchResponse($handle, $baseUrl . '/health'), true, 512, JSON_THROW_ON_ERROR);
for ($index = 0; $index < 5; $index++) {
    fetchResponse($handle, $baseUrl . '/ready');
}
$socket = stream_socket_client('tcp://' . parse_url($baseUrl, PHP_URL_HOST) . ':' . parse_url($baseUrl, PHP_URL_PORT), $errorCode, $error, 5);
ensure($socket !== false, 'Disconnect probe could not connect: ' . $error);
stream_set_timeout($socket, 3);
fwrite($socket, "GET /large-download HTTP/1.1\r\nHost: localhost\r\n\r\n");
// 看到首批字节即断开，覆盖触发轮换的文件响应无法通知正常发送完成的路径。
ensure(fread($socket, 1024) !== '', 'Disconnect probe received no response');
fclose($socket);
unset($handle);
$deadline = microtime(true) + 15;
$next = null;
do {
    $handle = curl_init($baseUrl . '/health');
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
    $body = curl_exec($handle);
    if ($body !== false && curl_getinfo($handle, CURLINFO_HTTP_CODE) === 200) {
        $next = json_decode($body, true);
        if (($next['worker']['pid'] ?? $before['worker']['pid']) !== $before['worker']['pid']) {
            break;
        }
    }
    usleep(100000);
} while (microtime(true) < $deadline);
ensure(isset($next['worker']['pid']) && $next['worker']['pid'] !== $before['worker']['pid'], 'Client disconnect prevented Worker rotation');
printf("PASS disconnect: threshold download aborted, Worker %d -> %d recovered\n", $before['worker']['pid'], $next['worker']['pid']);
