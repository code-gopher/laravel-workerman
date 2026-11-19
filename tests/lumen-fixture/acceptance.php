<?php

declare(strict_types=1);

$baseUrl = getenv('ACCEPTANCE_BASE_URL') ?: 'http://127.0.0.1:18767';
$deadline = microtime(true) + 90;
$response = false;
// 限制每次探测的等待时间，避免长连接阻塞整个就绪检查。
$httpContext = stream_context_create(['http' => ['timeout' => 2, 'header' => "Connection: close\r\n"]]);
while (microtime(true) < $deadline) {
    $response = @file_get_contents($baseUrl . '/ready', false, $httpContext);
    if ($response === 'ok') {
        break;
    }
    usleep(250000);
}
if ($response !== 'ok') {
    throw new RuntimeException('Lumen HTTP health check failed');
}

$connection = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST') ?: 'mariadb', getenv('DB_PORT') ?: '3306', getenv('DB_DATABASE') ?: 'workerman_test'),
    getenv('DB_USERNAME') ?: 'workerman',
    getenv('DB_PASSWORD') ?: 'workerman_test',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$connection->exec('DELETE FROM acceptance_requests');
$connection->exec("UPDATE acceptance_counters SET counter_value = 0 WHERE counter_key = 'total'");

$markers = [];
for ($index = 0; $index < 40; $index++) {
    $markers[] = sprintf('accept-%s-%02d', bin2hex(random_bytes(4)), $index);
}
$multiHandle = curl_multi_init();
$handles = [];
foreach ($markers as $marker) {
    $handle = curl_init($baseUrl . '/db/record/' . rawurlencode($marker));
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    curl_multi_add_handle($multiHandle, $handle);
    $handles[$marker] = $handle;
}
do {
    $status = curl_multi_exec($multiHandle, $running);
    if ($status > CURLM_OK) {
        throw new RuntimeException('curl_multi_exec failed: ' . curl_multi_strerror($status));
    }
    if ($running > 0) {
        curl_multi_select($multiHandle, 1.0);
    }
} while ($running > 0);

$seen = [];
$workers = [];
foreach ($handles as $marker => $handle) {
    $httpStatus = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $body = curl_multi_getcontent($handle);
    if ($httpStatus !== 200) {
        throw new RuntimeException(sprintf('Request %s returned HTTP %d: %s', $marker, $httpStatus, $body));
    }
    $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if (($decoded['marker'] ?? null) !== $marker
        || !is_string($decoded['payload'] ?? null)
        || !is_int($decoded['worker'] ?? null)
    ) {
        throw new RuntimeException('Request context mismatch for ' . $marker);
    }
    $seen[$marker] = $decoded['payload'];
    $workers[$decoded['worker']] = true;
    curl_multi_remove_handle($multiHandle, $handle);
    curl_close($handle);
}
curl_multi_close($multiHandle);
if (count($seen) !== count($markers)) {
    throw new RuntimeException('Concurrent response marker count mismatch');
}
if (count($workers) < 2) {
    throw new RuntimeException('Concurrent requests did not reach multiple Workerman processes');
}

// 逐条比对响应和持久化数据，避免总数正确但请求上下文串用。
$records = $connection->query('SELECT marker, payload FROM acceptance_requests')->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($seen as $marker => $payload) {
    if (!isset($records[$marker]) || !hash_equals($payload, $records[$marker])) {
        throw new RuntimeException('Persisted payload mismatch for ' . $marker);
    }
}
$stored = count($records);
$count = (int) $connection->query("SELECT counter_value FROM acceptance_counters WHERE counter_key = 'total'")->fetchColumn();
if ($stored !== count($markers) || $count !== count($markers)) {
    throw new RuntimeException(sprintf('Database concurrency mismatch: rows=%d counter=%d expected=%d', $stored, $count, count($markers)));
}

$rollbackMarker = 'rollback-' . bin2hex(random_bytes(6));
$rollbackHandle = curl_init($baseUrl . '/db/record/' . rawurlencode($rollbackMarker));
curl_setopt_array($rollbackHandle, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => ['X-Acceptance-Fail: yes'],
]);
$rollbackResponse = curl_exec($rollbackHandle);
$rollbackStatus = curl_getinfo($rollbackHandle, CURLINFO_HTTP_CODE);
curl_close($rollbackHandle);
if ($rollbackResponse === false || $rollbackStatus !== 500) {
    throw new RuntimeException('Expected the rollback probe to return HTTP 500');
}
$rollbackCheck = $connection->prepare('SELECT COUNT(*) FROM acceptance_requests WHERE marker = ?');
$rollbackCheck->execute([$rollbackMarker]);
if ((int) $rollbackCheck->fetchColumn() !== 0) {
    throw new RuntimeException('Uncommitted request row survived request cleanup');
}

$afterRollback = file_get_contents($baseUrl . '/db/record/post-rollback-' . bin2hex(random_bytes(4)), false, $httpContext);
if ($afterRollback === false) {
    throw new RuntimeException('Database is not usable after request rollback');
}

printf("Lumen HTTP + MariaDB integration passed: %d concurrent writes, exact counter, rollback recovery.\n", count($markers));
