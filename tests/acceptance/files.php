<?php

declare(strict_types=1);

require_once __DIR__ . '/http.php';

$baseUrl = getenv('ACCEPTANCE_BASE_URL') ?: 'http://127.0.0.1:18768';
$handle = curl_init();
$full = fetchResponse($handle, $baseUrl . '/download');
$expected = file_get_contents(dirname(__DIR__) . '/laravel-fixture/fixtures/download.txt');
ensure($full === $expected, 'File bytes differ');
$range = fetchResponse($handle, $baseUrl . '/download', ['Range: bytes=2-5'], 206);
ensure($range === substr($expected, 2, 4), 'Range bytes differ');
// 显式闭区间才能在 Symfony 各版本一致命中 416；起点大于隐式终点的请求可被框架忽略。
fetchResponse($handle, $baseUrl . '/download', ['Range: bytes=99999999-100000000'], 416);
curl_setopt($handle, CURLOPT_NOBODY, true);
fetchResponse($handle, $baseUrl . '/download');
curl_setopt($handle, CURLOPT_NOBODY, false);
ensure(fetchResponse($handle, $baseUrl . '/download') === $expected, 'File HEAD corrupted keep-alive');
// 真实 TCP 上一次写入两个 HTTP 请求，第二份响应必须排在完整文件之后。
$host = parse_url($baseUrl, PHP_URL_HOST);
$port = parse_url($baseUrl, PHP_URL_PORT);
$socket = stream_socket_client('tcp://' . $host . ':' . $port, $errorCode, $error, 5);
ensure($socket !== false, 'Pipeline socket failed: ' . $error);
stream_set_timeout($socket, 30);
fwrite($socket, "GET /large-download HTTP/1.1\r\nHost: localhost\r\n\r\nGET /ready HTTP/1.1\r\nHost: localhost\r\n\r\n");
$buffer = '';
while (strpos($buffer, "\r\n\r\n") === false) {
    $chunk = fread($socket, 8192);
    ensure($chunk !== false && $chunk !== '', 'Pipeline headers missing');
    $buffer .= $chunk;
}
list($headers, $body) = explode("\r\n\r\n", $buffer, 2);
ensure(preg_match('/Content-Length:\s*(\d+)/i', $headers, $matches) === 1, 'Pipeline file length missing');
$length = (int) $matches[1];
while (strlen($body) < $length) {
    $chunk = fread($socket, min(1048576, $length - strlen($body)));
    ensure($chunk !== false && $chunk !== '', 'Pipeline file truncated');
    $body .= $chunk;
}
$fileBody = substr($body, 0, $length);
ensure(hash('sha256', $fileBody) === hash_file('sha256', dirname(__DIR__) . '/laravel-fixture/fixtures/large.bin'), 'Pipeline inserted response into file');
$next = substr($body, $length);
while (strpos($next, "\r\n\r\n") === false || substr($next, -2) !== 'ok') {
    $chunk = fread($socket, 8192);
    ensure($chunk !== false && $chunk !== '', 'Pipeline second response missing');
    $next .= $chunk;
}
ensure(strpos($next, 'HTTP/1.1 200') === 0, 'Pipeline response order changed');
fclose($socket);
echo "PASS files: exact body, HEAD keep-alive, ranges 206/416, large-file HTTP pipeline ordering\n";
