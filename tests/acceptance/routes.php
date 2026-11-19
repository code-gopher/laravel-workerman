<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

// 两种框架复用相同探针，避免仅验证框架无关的模拟路径。
// Lumen 的认证提供者会惰性加载 auth 配置，先解析管理器再设置测试 guard。
$auth = app('auth');
app('config')->set('auth.guards.acceptance', ['driver' => 'request', 'provider' => null]);
app('config')->set('auth.defaults.guard', 'acceptance');
Auth::viaRequest('request',
    /**
     * 将请求头映射到独立测试身份，检查下一请求是否遗留认证用户。
     *
     * @param Request $request 当前请求
     * @return GenericUser|null
     */
    function (Request $request): ?GenericUser {
        $identity = $request->header('X-Acceptance-User');

        return $identity === null ? null : new GenericUser(['id' => $identity]);
    }
);

$register('/acceptance/context',
    /**
     * 返回请求关联信息和数据库连接状态，便于核对同一 Worker 的隔离。
     *
     * @param Request $request 当前请求
     * @return Response
     */
    function (Request $request): Response {
        $connection = DB::connection();
        $transactionLevel = $connection->transactionLevel();
        $connectionId = (int) $connection->selectOne('SELECT CONNECTION_ID() AS id')->id;
        $marker = $request->input('marker');
        $response = new Response(json_encode([
            'marker' => $marker,
            'header' => $request->header('X-Acceptance-Marker'),
            'cookie' => $request->cookie('acceptance_marker'),
            'user' => Auth::guard('acceptance')->id(),
            'url' => app('url')->to('/probe'),
            'worker' => getmypid(),
            'connection' => $connectionId,
            'transaction_level' => $transactionLevel,
            'memory' => memory_get_usage(true),
        ], JSON_THROW_ON_ERROR), 200, ['Content-Type' => 'application/json']);

        return $response;
    }
);

$register('/acceptance/termination',
    /**
     * 终止回调写入唯一记录，重复执行会触发主键冲突并使验收失败。
     *
     * @param Request $request 当前请求
     * @return Response
     */
    function (Request $request): Response {
        $marker = $request->input('marker');
        app()->terminating(function () use ($marker): void {
            DB::table('acceptance_requests')->insert(['marker' => $marker, 'payload' => 'terminated-once']);
        });

        return new Response('scheduled');
    }
);

$register('/acceptance/write',
    /**
     * 在事务内写入独立记录，外部客户端逐条核验持久化结果。
     *
     * @param Request $request 当前请求
     * @return Response
     */
    function (Request $request): Response {
        $marker = $request->input('marker');
        $payload = hash('sha256', $marker);
        DB::transaction(function () use ($marker, $payload): void {
            DB::table('acceptance_requests')->insert(['marker' => $marker, 'payload' => $payload]);
        });

        return new Response(json_encode(['marker' => $marker, 'payload' => $payload, 'worker' => getmypid()], JSON_THROW_ON_ERROR), 200, ['Content-Type' => 'application/json']);
    }
);

$register('/acceptance/transaction',
    /**
     * 故意遗留嵌套事务，验证请求结束后均被回滚。
     *
     * @param Request $request 当前请求
     * @return Response
     * @throws RuntimeException 用于测试异常清理路径
     */
    function (Request $request): Response {
        DB::beginTransaction();
        DB::beginTransaction();
        DB::table('acceptance_requests')->insert([
            'marker' => $request->input('marker'),
            'payload' => 'must-rollback',
        ]);
        if ($request->input('fail') === 'yes') {
            throw new RuntimeException('acceptance nested transaction failure');
        }

        return new Response('uncommitted', 200);
    }
);
