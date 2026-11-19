<?php

declare(strict_types=1);

namespace CodeGopher\LaravelWorkerman\Tests\Acceptance\Lifecycle;

// 只使用真实 HTTP，不在客户端引导 Laravel 或直接调用 cleaner。
require_once __DIR__ . '/http.php';

/**
 * 解码真实响应并检查 Worker 身份，进程切换不能被当成隔离成功。
 *
 * @param resource|\CurlHandle $handle 复用的 cURL 连接句柄
 * @param string $url 请求地址
 * @param int|null $worker 首个响应确定的 Worker 进程编号
 * @param array<string> $headers 请求头
 * @return array<string, mixed>
 * @throws \RuntimeException 当传输、状态、结构或 Worker 身份不符合预期时
 * @throws \JsonException 当响应不是合法 JSON 时
 */
function lifecycleResponse($handle, string $url, ?int &$worker, array $headers = []): array
{
    $response = json_decode(\fetchResponse($handle, $url, $headers), true, 512, JSON_THROW_ON_ERROR);
    \ensure(is_array($response), 'Lifecycle response must be a JSON object: ' . $url);
    \ensure(isset($response['worker']) && is_int($response['worker']) && $response['worker'] > 0,
        'Lifecycle response has no valid worker PID: ' . $url);
    if ($worker === null) {
        $worker = $response['worker'];
    }
    \ensure($response['worker'] === $worker,
        'Worker changed during lifecycle regression; cross-request isolation was not verified: ' . $url);

    return $response;
}

/**
 * 对 URL 行为和默认参数做完整断言，防止仅清理一部分状态仍通过。
 *
 * @param mixed $actual 服务端 URL 快照
 * @param string $origin 预期协议与域名
 * @param string $tenant 预期默认租户参数
 * @param array<string, string> $defaults 预期默认参数全集
 * @param string $context 当前断言阶段
 * @return void
 * @throws \RuntimeException 当 URL 或默认参数不符合预期时
 */
function ensureUrlSnapshot($actual, string $origin, string $tenant, array $defaults, string $context): void
{
    \ensure(is_array($actual), $context . ': missing URL snapshot');
    \ensure(($actual['plain'] ?? null) === $origin . '/acceptance/lifecycle/url-target',
        $context . ': forced root or scheme leaked: ' . json_encode($actual));
    \ensure(($actual['named'] ?? null) === $origin . '/acceptance/lifecycle/url-target/' . $tenant,
        $context . ': named route default was not restored: ' . json_encode($actual));
    \ensure(($actual['defaults'] ?? null) === $defaults,
        $context . ': URL defaults differ: ' . json_encode($actual));
}

// 必须在主会话重启加载新路由后执行；首次检查前不能请求 /，也不能预热业务根路由。
$baseUrl = rtrim(getenv('ACCEPTANCE_BASE_URL') ?: 'http://127.0.0.1:18768', '/');
$parts = parse_url($baseUrl);
\ensure(is_array($parts) && isset($parts['scheme'], $parts['host'])
    && in_array($parts['scheme'], ['http', 'https'], true)
    && !isset($parts['path']) && !isset($parts['query'])
    && !isset($parts['fragment']) && !isset($parts['user']) && !isset($parts['pass']),
    'ACCEPTANCE_BASE_URL must be an HTTP(S) origin without a path, query or credentials');
$handle = curl_init();
\ensure($handle !== false, 'Unable to initialize lifecycle HTTP client');
$worker = null;
$prefix = $baseUrl . '/acceptance/lifecycle';
$run = bin2hex(random_bytes(12));

$boot = lifecycleResponse($handle, $prefix . '/boot', $worker);
\ensure(($boot['registrations'] ?? null) === 1, 'Lifecycle boot registration did not execute exactly once');
\ensure(($boot['root_matches'] ?? null) === 0 && array_key_exists('root_marker', $boot) && $boot['root_marker'] === null,
    'Root route was dispatched before the explicit request; rerun only on a freshly restarted Worker');

// 连续比较多个保留引用的 scoped 实例；同一请求内的两次解析必须返回同一个对象。
$scopeIds = [];
for ($index = 0; $index < 8; $index++) {
    $marker = $run . '-' . $index;
    $context = lifecycleResponse($handle, $prefix . '/controller?marker=' . rawurlencode($marker), $worker,
        ['X-Lifecycle-Marker: ' . $marker]);
    foreach (['constructor_marker', 'constructor_header', 'action_marker', 'action_header', 'scope_marker'] as $field) {
        \ensure(($context[$field] ?? null) === $marker,
            'Stale constructor Request or scoped binding at ' . $field . ': ' . json_encode($context));
    }
    \ensure(($context['same_request'] ?? null) === true, 'Constructor and action received different Request instances');
    \ensure(($context['same_scope'] ?? null) === true, 'Scoped binding was not shared within one request');
    $scopeId = $context['scope_id'] ?? null;
    \ensure(is_int($scopeId) && $scopeId > 0 && !in_array($scopeId, $scopeIds, true),
        'Scoped object survived across requests: ' . json_encode($context));
    $scopeIds[] = $scopeId;

    // 每次都使用新验证器，同时执行正反样本，规则注册仅允许发生在启动期。
    $validation = lifecycleResponse($handle, $prefix . '/validator', $worker);
    \ensure(($validation['registrations'] ?? null) === 1, 'Boot registrations repeated during requests');
    \ensure(($validation['valid_passes'] ?? null) === true && ($validation['invalid_fails'] ?? null) === true,
        'Boot validator extension did not accept and reject the expected values');
    \ensure(($validation['invalid_message'] ?? null) === '启动期生命周期规则拒绝该值。',
        'Boot validator extension or its message was lost across requests');
}

$defaults = ['lifecycleTenant' => 'boot-tenant'];
$baseline = lifecycleResponse($handle, $prefix . '/url', $worker);
ensureUrlSnapshot($baseline['before'] ?? null, $baseUrl, 'boot-tenant', $defaults, 'initial URL baseline');
\ensure(($baseline['after'] ?? null) === ($baseline['before'] ?? null), 'Read-only URL probe changed its own baseline');
$oppositeScheme = $parts['scheme'] === 'https' ? 'http' : 'https';
$oppositeOrigin = $oppositeScheme . substr($baseUrl, strlen($parts['scheme']));
foreach (['root', 'scheme', 'defaults', 'all'] as $mode) {
    $mutated = lifecycleResponse($handle, $prefix . '/url?mutate=' . $mode, $worker);
    ensureUrlSnapshot($mutated['before'] ?? null, $baseUrl, 'boot-tenant', $defaults, $mode . ' before mutation');
    $origin = $baseUrl;
    if ($mode === 'root') {
        $origin = $parts['scheme'] . '://lifecycle-forced.invalid';
    } elseif ($mode === 'scheme') {
        $origin = $oppositeOrigin;
    } elseif ($mode === 'all') {
        $origin = $oppositeScheme . '://lifecycle-forced.invalid';
    }
    $hasDefaults = $mode === 'defaults' || $mode === 'all';
    ensureUrlSnapshot($mutated['after'] ?? null, $origin, $hasDefaults ? 'request-tenant' : 'boot-tenant',
        $hasDefaults ? ['lifecycleTenant' => 'request-tenant', 'lifecycleOnly' => 'must-disappear'] : $defaults,
        $mode . ' mutation positive control');

    // 连续两个干净请求都必须恢复启动默认值，不能仅在第一个请求偶然正确。
    for ($read = 0; $read < 2; $read++) {
        $restored = lifecycleResponse($handle, $prefix . '/url', $worker);
        ensureUrlSnapshot($restored['before'] ?? null, $baseUrl, 'boot-tenant', $defaults, $mode . ' next request');
        ensureUrlSnapshot($restored['after'] ?? null, $baseUrl, 'boot-tenant', $defaults, $mode . ' stable restoration');
    }
}

// 阳性对照证明监听器能观察到现有根路由，避免未挂接监听器时零计数假通过。
$rootMarker = $run . '-root';
$root = \fetchResponse($handle, $baseUrl . '/', ['X-Lifecycle-Marker: ' . $rootMarker]);
\ensure($root === 'laravel-ready', 'Explicit root request did not reach the existing Laravel root route');
$afterRoot = lifecycleResponse($handle, $prefix . '/boot', $worker);
\ensure(($afterRoot['root_matches'] ?? null) === 1 && ($afterRoot['root_marker'] ?? null) === $rootMarker,
    'Boot marker failed its explicit root-request positive control');
\ensure(($afterRoot['registrations'] ?? null) === 1, 'Lifecycle routes were registered again during root dispatch');

// 仅所有真实断言完成才输出 PASS；任何异常都保留为非零退出，不捕获后伪报成功。
echo 'PASS lifecycle: worker=' . $worker
    . ', 8 constructor/scoped checks, 8 boot validator checks, 4 URL mutation/restoration modes, '
    . "boot root-dispatch negative and positive controls\n";
