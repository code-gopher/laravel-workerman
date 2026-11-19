<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

// 与 Lumen 使用相同的数据库、身份和请求隔离探针。
$register = [Route::class, 'get'];
require dirname(__DIR__, 2) . '/acceptance/routes.php';
require dirname(__DIR__, 2) . '/acceptance/lifecycle-routes.php';

/**
 * 验证就绪请求经过 Laravel 中间件和路由。
 *
 * @return Response
 */
Route::get('/ready', static function (): Response {
    return new Response('ok');
});

// Laravel 的启动探针会请求根路径，显式返回成功状态以验证应用已就绪。
Route::get('/', static function (): Response {
    return new Response('laravel-ready');
});

Route::get('/echo', static function (\Illuminate\Http\Request $request): Response {
    return new Response(json_encode([
        'marker' => $request->query('marker'),
        'path' => $request->path(),
        'host' => $request->getHost(),
        'token' => $request->header('X-Test-Token'),
    ], JSON_UNESCAPED_SLASHES));
});

/**
 * 通过独立客户端 Cookie 验证 Laravel 文件会话与一次性 flash 生命周期。
 *
 * @param \Illuminate\Http\Request $request 当前会话请求
 * @return Response
 */
Route::get('/acceptance/session', static function (\Illuminate\Http\Request $request): Response {
    $session = $request->session();
    if ($request->query->has('value')) {
        $session->put('value', $request->query('value'));
    }
    if ($request->query->has('flash')) {
        $session->flash('notice', $request->query('flash'));
    }

    return new Response(json_encode([
        'value' => $session->get('value'),
        'notice' => $session->get('notice'),
    ], JSON_THROW_ON_ERROR), 200, ['Content-Type' => 'application/json']);
});

/**
 * 返回真实 multipart 上传文件的元数据，验证 Workerman 到 Laravel 的文件转换。
 *
 * @param \Illuminate\Http\Request $request 当前上传请求
 * @return Response
 */
Route::post('/acceptance/upload', static function (\Illuminate\Http\Request $request): Response {
    $file = $request->file('file');
    if ($file === null || !$file->isValid()) {
        return new Response(json_encode(['error' => 'upload missing or invalid']), 422, ['Content-Type' => 'application/json']);
    }

    return new Response(json_encode([
        'name' => $file->getClientOriginalName(),
        'size' => $file->getSize(),
        'sha256' => hash_file('sha256', $file->getRealPath()),
        'valid' => $file->isValid(),
    ], JSON_THROW_ON_ERROR), 200, ['Content-Type' => 'application/json']);
});
