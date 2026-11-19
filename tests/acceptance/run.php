
// 主动终止已建立的测试连接，检查框架下一次查询是否恢复连接。
$before = json_decode(fetchResponse($handle, $baseUrl . '/acceptance/context'), true, 512, JSON_THROW_ON_ERROR);
$pdo->exec('KILL CONNECTION ' . (int) $before['connection']);
$after = json_decode(fetchResponse($handle, $baseUrl . '/acceptance/context'), true, 512, JSON_THROW_ON_ERROR);
ensure($after['worker'] === $before['worker'], 'Reconnect probe changed Worker');
ensure($after['connection'] !== $before['connection'], 'Killed database connection was not replaced');
echo "PASS database reconnect after connection termination\n";
unset($handle);

$rounds = (int) (getenv('ACCEPTANCE_ROUNDS') ?: 50);
$concurrency = 32;
$workers = [];
$start = microtime(true);
for ($round = 0; $round < $rounds; $round++) {
    $multi = curl_multi_init();
    $handles = [];
    for ($index = 0; $index < $concurrency; $index++) {
        $marker = $run . '-' . $round . '-' . $index;
        $request = curl_init($baseUrl . '/acceptance/' . ($round % 2 === 0 ? 'context' : 'write') . '?marker=' . $marker);
        curl_setopt_array($request, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['X-Acceptance-Marker: ' . $marker]]);
        curl_multi_add_handle($multi, $request);
        $handles[$marker] = $request;
    }
    do {
        $status = curl_multi_exec($multi, $running);
        ensure($status === CURLM_OK, 'Multi transport failed');
        if ($running > 0 && curl_multi_select($multi, 0.5) === -1) {
            usleep(1000);
        }
    } while ($running > 0);
    foreach ($handles as $marker => $request) {
        ensure(curl_errno($request) === 0, curl_error($request));
        ensure(curl_getinfo($request, CURLINFO_HTTP_CODE) === 200, 'Concurrent request failed');
        $data = json_decode(curl_multi_getcontent($request), true, 512, JSON_THROW_ON_ERROR);
        ensure($data['marker'] === $marker, 'Concurrent marker mismatch');
        if ($round % 2 === 0) {
            ensure($data['header'] === $marker, 'Concurrent header mismatch');
            ensure($data['user'] === null && $data['cookie'] === null && $data['transaction_level'] === 0, 'Concurrent context contaminated');
        } else {
            ensure($data['payload'] === hash('sha256', $marker), 'Concurrent write payload mismatch');
        }
        $workers[$data['worker']] = true;
        curl_multi_remove_handle($multi, $request);
        unset($request);
    }
    curl_multi_close($multi);
}
// 使用本轮唯一前缀过滤数据，允许不同框架验收同时访问测试库。
$query = $pdo->prepare('SELECT marker, payload FROM acceptance_requests WHERE marker LIKE ?');
$query->execute([$run . '-%']);
$records = $query->fetchAll(PDO::FETCH_KEY_PAIR);
ensure(count($records) === intdiv($rounds, 2) * $concurrency, 'Concurrent write count mismatch');
foreach ($records as $marker => $payload) {
    ensure(hash_equals(hash('sha256', $marker), $payload), 'Stored concurrent payload mismatch');
}
printf("PASS sustained: %d requests, writes=%d, concurrency=%d, workers=%d, elapsed=%.2fs\n", $rounds * $concurrency, count($records), $concurrency, count($workers), microtime(true) - $start);
