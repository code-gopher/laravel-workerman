<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

$router->get('/ready', function (): Response {
    return new Response('ok', 200, ['Content-Type' => 'text/plain']);
});

$router->get('/db/record/{marker}', function (Request $request, string $marker): Response {
    if (($request->header('X-Acceptance-Fail') ?? null) === 'yes') {
        DB::beginTransaction();
        DB::table('acceptance_requests')->insert([
            'marker' => $marker,
            'payload' => 'should-rollback',
        ]);

        // 异常请求必须在 finally 清理阶段回滚未提交事务。
        throw new RuntimeException('acceptance rollback probe');
    }

    $payload = hash('sha256', $marker . ':' . microtime(true));
    DB::table('acceptance_requests')->insert([
        'marker' => $marker,
        'payload' => $payload,
    ]);
    DB::table('acceptance_counters')->where('counter_key', 'total')->increment('counter_value');
    $record = DB::table('acceptance_requests')->where('marker', $marker)->first();
    $count = DB::table('acceptance_counters')->where('counter_key', 'total')->value('counter_value');

    return new Response(json_encode([
        'marker' => $marker,
        'payload' => $record->payload,
        'count' => (int) $count,
        'worker' => getmypid(),
    ], JSON_THROW_ON_ERROR), 200, ['Content-Type' => 'application/json']);
});

$router->get('/delay/{marker}', function (string $marker): Response {
    usleep(300000);

    return new Response(json_encode(['marker' => $marker], JSON_THROW_ON_ERROR), 200, ['Content-Type' => 'application/json']);
});
