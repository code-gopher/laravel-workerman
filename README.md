# Laravel Workerman

使用 Workerman 以常驻内存模式运行 Laravel/Lumen 应用，减少每次请求重复引导框架的开销。实际性能取决于业务、依赖、Worker 数量和部署环境，应以应用自身压测为准。

生产部署前请确认本文列出的请求隔离、代理、文件传输和定时任务适用范围。生产使用 Linux、关闭调试模式，并在应用中验证自己的关键业务流程；Composer 兼容范围不代表全部组合均已实测。

## 版本要求

| 依赖 | 版本 |
|------|------|
| PHP | ^7.2 或 ^8.0 |
| Laravel | Illuminate ^6.0 至 ^12.0 |
| Lumen | 需要与 Illuminate 兼容的 Lumen 版本；本仓库未覆盖完整版本矩阵 |
| Workerman | ^4.0 或 ^5.0 |

> 上述范围是 Composer 约束，不代表每个 PHP、Laravel/Lumen 与 Workerman 组合均已完成集成验证。请以 CI 实际通过的组合为准。

## 特性

- 🚀 **高性能**: 常驻内存，避免重复加载框架
- 🔄 **自动轮换**: 达到动态请求上限后，排空整个 HTTP Worker 的已有响应，最多等待 30 秒后替换子进程
- 📁 **静态文件**: 内置静态文件服务，适合常规静态资源
- 🛠 **易于集成**: 一键安装，开箱即用
- 📊 **性能统计**: 自动统计请求数、响应时间、内存使用
- 🔀 **双框架支持**: 同时兼容 Laravel 和 Lumen 框架

## 安装

```bash
composer require code-gopher/laravel-workerman
```

PHP 版本和框架依赖由 Composer 根据当前项目解析；本库当前要求 PHP `^7.2|^8.0`、Workerman `^4.0|^5.0` 和 Illuminate `^6.0` 至 `^12.0`。如需复现依赖，请将应用的 `composer.lock` 纳入版本控制。

## 配置

### Laravel

发布配置文件和启动脚本：

```bash
php artisan vendor:publish --tag=workerman --force
```

这将发布：
- `config/workerman.php` - 配置文件
- `workerman.php` - 启动脚本

### Lumen

由于 Lumen 不支持 `vendor:publish`，需要手动复制文件：

```bash
# 复制配置文件
cp vendor/code-gopher/laravel-workerman/config/workerman.php config/workerman.php

# 复制启动脚本
cp vendor/code-gopher/laravel-workerman/workerman.php workerman.php
```

然后在 `bootstrap/app.php` 中注册服务提供者：

```php
$app->register(CodeGopher\LaravelWorkerman\WorkermanServiceProvider::class);
```

## 使用

### 启动服务

```bash
# 前台启动
php workerman.php start

# 后台启动（仅独立运行的 Linux/Mac；Docker、systemd、Supervisor 下使用前台模式）
php workerman.php start -d

# 自定义参数
php workerman.php --port=9000 --workers=8 --max=5000 --debug start
```

### 命令行参数

| 参数 | 短选项 | 说明 | 默认值 |
|------|--------|------|--------|
| `--port` | `-p` | 监听端口 | 8080 |
| `--workers` | `-w` | Worker 进程数 | 4 |
| `--max` | `-m` | 单个 Worker 最大请求数 | 10000 |
| `--debug` | - | 启用调试模式 | false |

### 停止服务

```bash
# Linux/Mac
php workerman.php stop

# Windows
Ctrl+C
```

### 其他命令（仅 Linux/Mac）

```bash
php workerman.php restart   # 重启
php workerman.php reload    # 平滑重载
php workerman.php status    # 查看状态
```

## 配置选项

编辑 `config/workerman.php`：

```php
// 此配置在框架引导前加载，路径不要依赖 base_path()、storage_path() 等辅助函数。
$basePath = dirname(__DIR__);

return [
    // 监听地址
    'host'         => env('WORKERMAN_HOST', '0.0.0.0'),

    // 监听端口
    'port'         => env('WORKERMAN_PORT', 8080),

    // Worker 进程数（Windows 只能为 1）
    'workers'      => env('WORKERMAN_WORKERS', 4),

    // 动态请求上限；达到后排空整个 Worker，最多等待 30 秒；0 表示不自动轮换
    'max_requests' => env('WORKERMAN_MAX_REQUESTS', 10000),

    // 调试模式
    'debug'        => env('WORKERMAN_DEBUG', false),

    // 进程名称
    'name'         => env('WORKERMAN_NAME', 'laravel-workerman'),

    // 静态文件配置
    'static'       => [
        'enable' => true,
        'path'   => $basePath . '/public',
    ],

    // 日志配置
    'log'          => [
        'file' => $basePath . '/storage/workerman/workerman.log',
    ],

    // 自定义清理器（每次请求结束后执行）
    'cleaners'     => [
        // App\Workerman\Cleaners\MyCleaner::class,
        // App\Workerman\Cleaners\AnotherCleaner::class,
    ],

    // 定时任务（由独立单进程 Worker 注册，不受 HTTP 请求数轮换影响）
    'tasks'        => [
        // App\Workerman\Tasks\SyncStatisticsTask::class,
    ],
];
```

### 响应支持边界

当前适配器支持普通 HTTP 的 Symfony/Laravel 字符串响应（包括 HTML、JSON、状态码、响应头和多个 `Set-Cookie`），并将常规 `BinaryFileResponse` 文件交给 Workerman 发送；单段 Range 请求由框架解析后映射到文件区间，多段范围响应会明确报错。依赖外部 Web 服务器的 `X-Sendfile`/`X-Accel-Redirect`、文件发送后自动删除、`StreamedResponse`/SSE 暂不支持；文件流式传输不等于支持框架流式响应。TLS 代理场景不自动信任反向代理传入的 HTTPS 标记；部署在 TLS 终止代理后，应由应用侧明确配置受信代理策略。

达到 `max_requests` 后，停止接收新连接并等待整个 Worker 的已有发送缓冲和文件发送器排空，最长等待 30 秒；超时会关闭剩余连接，大文件或慢客户端可能被截断。此阈值统计进入框架的动态请求（包括失败请求），不统计内置健康检查、调试状态与直接命中的静态文件；`0` 关闭按请求数轮换。详见生产文档中的超时和文件下载建议。

## 自定义清理器

在 Workerman 常驻内存环境下，某些资源需要在每次请求结束后清理，以防止状态污染。

### 内置清理器

已内置以下清理器，自动执行：

- `GlobalVariableCleaner` - 清理 PHP 超全局变量
- `RequestInstanceCleaner` - 清理请求级别容器实例
- `FacadeCleaner` - 清理 Facade 缓存
- `SessionCleaner` - 对仍启动的会话补保存，并重置已创建会话驱动的请求状态，避免重复保存和 flash 老化
- `AuthCleaner` - 重置已解析 Guard 的用户、请求及内置 SessionGuard 的相关标志；自定义 Guard 仍需审计
- `CookieCleaner` - 清理 Cookie 队列
- `ValidatorCleaner` - 保留验证工厂及启动时注册的自定义规则，不销毁工厂；单次 Validator 由工厂创建
- `UrlGeneratorCleaner` - 恢复启动时的 URL 配置基线，重置请求引用
- `DatabaseCleaner` - 清理数据库查询日志、回滚未提交事务

### 创建自定义清理器

内置请求隔离不等于任意单例都安全。自定义 Guard、第三方 singleton、静态缓存或在构造函数中捕获 Request/用户/租户的对象，仍可能跨请求保留状态；应审计并创建自定义清理器，或改为请求级服务。清理器与任务类当前使用无参构造实例化，不会自动进行构造函数依赖注入。

如果你的应用有自定义的单例或静态变量需要清理，可以创建自定义清理器：

```php
<?php

namespace App\Workerman\Cleaners;

use CodeGopher\LaravelWorkerman\Contracts\CleanerInterface;

class MyCleaner implements CleanerInterface
{
    public function clean($app): void
    {
        // 清理自定义缓存
        MyCache::flush();

        // 重置单例状态
        MySingleton::reset();

        // 清理静态变量
        MyService::$data = null;
    }
}
```

### 注册自定义清理器

在 `config/workerman.php` 中注册：

```php
'cleaners' => [
    App\Workerman\Cleaners\MyCleaner::class,
    App\Workerman\Cleaners\AnotherCleaner::class,
],
```

## 定时任务

配置了任务后，服务会创建一个独立、无 HTTP 监听的单进程任务 Worker。任务不受 HTTP Worker 的 `max_requests` 轮换影响，耗时任务不会直接占用 HTTP Worker，但会阻塞同一任务进程中的其他定时器。此功能需要 Linux/macOS；Windows 原生环境不支持该独立任务进程，建议使用 Linux Docker。

独立进程只避免同一服务实例内随 HTTP Worker 数重复注册；多个容器、多个副本和滚动发布仍可能同时执行任务。生产任务必须按业务实现分布式锁与幂等，不能将其视为恰好执行一次的持久化队列。每次任务执行不经过 HTTP 请求清理流程，任务必须自行提交/回滚事务，并在 `finally` 中释放锁、清理请求无关的临时状态和连接资源。任务类需要实现 `TaskInterface`：

```php
<?php

namespace App\Workerman\Tasks;

use CodeGopher\LaravelWorkerman\Contracts\TaskInterface;

class SyncStatisticsTask implements TaskInterface
{
    public function getInterval(): float
    {
        return 60;
    }

    public function handle(): void
    {
        StatisticsService::sync();
    }
}
```

在 `config/workerman.php` 注册：

```php
'tasks' => [
    App\Workerman\Tasks\SyncStatisticsTask::class,
],
```

## 环境变量

```ini
WORKERMAN_HOST=0.0.0.0
WORKERMAN_PORT=8080
WORKERMAN_WORKERS=4
WORKERMAN_MAX_REQUESTS=10000
WORKERMAN_DEBUG=false
WORKERMAN_NAME=laravel-workerman
```

## 项目结构

```
src/
├── Bootstrap.php              # 引导类 - 环境检查、参数解析
├── Config/
│   └── WorkermanConfig.php    # 配置管理器 - 多级配置覆盖
├── Contracts/
│   ├── FrameworkAdapter.php   # 框架适配器接口
│   ├── CleanerInterface.php   # 清理器接口
│   └── TaskInterface.php      # 定时任务接口
├── Adapters/
│   ├── AdapterFactory.php     # 适配器工厂 - 自动检测框架类型
│   ├── LaravelAdapter.php     # Laravel 适配器
│   └── LumenAdapter.php       # Lumen 适配器
├── Cleaners/                   # 内置清理器
│   ├── GlobalVariableCleaner.php
│   ├── RequestInstanceCleaner.php
│   ├── FacadeCleaner.php
│   ├── SessionCleaner.php
│   ├── AuthCleaner.php
│   ├── CookieCleaner.php
│   ├── ValidatorCleaner.php
│   ├── UrlGeneratorCleaner.php
│   └── DatabaseCleaner.php
├── CleanerManager.php         # 清理器管理器
├── TaskManager.php            # 定时任务管理器
├── WorkermanServer.php        # 服务器类 - Worker 生命周期管理
├── AppManager.php             # 应用管理器 - 统一处理 Laravel/Lumen
├── StaticFileHandler.php      # 静态文件处理器
├── Logger.php                 # 日志工具
└── WorkermanServiceProvider.php
```

## 架构说明

### 适配器模式

项目使用适配器模式来兼容 Laravel 和 Lumen 两个框架：

```
┌─────────────────┐
│   AppManager    │
└────────┬────────┘
         │
    ┌────▼────┐
    │ Factory │ ──── 自动检测框架类型
    └────┬────┘
         │
    ┌────┴────┐
    │         │
┌───▼───┐ ┌───▼───┐
│Laravel│ │ Lumen │
│Adapter│ │Adapter│
└───────┘ └───────┘
```

**核心差异处理：**

| 特性 | Laravel | Lumen |
|------|---------|-------|
| HTTP Kernel | ✅ 有 | ❌ 无 |
| Facade | 默认启用 | 需手动启用 |
| config_path() | ✅ 有 | ❌ 无 |
| vendor:publish | ✅ 有 | ❌ 无 |

## 注意事项

### 1. Session 和 Cache
- 建议使用 Redis 驱动，避免文件锁竞争

### 2. 代码与配置更新
- 修改业务代码后需要执行 `php workerman.php reload` 或重启服务；仅修改磁盘文件不会更新常驻对象
- 修改监听地址、端口、Worker 数量、任务列表或其他启动配置后，应重启 master/替换容器；不要依赖 reload 重新解析这些配置
- 使用 Laravel 配置缓存时，先在目标环境重建缓存；缓存存在时不会重新加载 `.env` 或 `config/workerman.php`，环境变量也不会覆盖缓存中的已有配置值

### 3. 静态变量
- 避免在静态变量中存储请求相关数据，可能导致数据污染

### 4. 单例模式
- 注意 Laravel/Lumen 容器中的单例在多次请求间共享

### 5. Windows 限制
- Windows 下只能使用单进程模式，按请求数自动轮换被禁用
- 不支持 `stop`、`restart`、`reload`、`status` 命令
- 配置定时任务时需要独立任务 Worker，Windows 原生环境不支持；生产环境使用 Linux 或 Linux Docker

### 6. Lumen 特殊配置

在 Lumen 中使用时，确保在 `bootstrap/app.php` 中启用 Facade（如果需要）：

```php
$app->withFacades();
```

## 本地开发调试

在 Laravel/Lumen 项目中使用本地开发版本：

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "/path/to/laravel-workerman",
            "options": { "symlink": true }
        }
    ],
    "require": {
        "code-gopher/laravel-workerman": "@dev"
    }
}
```

```bash
composer update "code-gopher/laravel-workerman:@dev" -vvv
```

## 健康检查

内置健康检查端点，供负载均衡器和监控系统使用。HTTP 应用初始化失败时，`/health` 和业务请求持续返回 `503`，失败进程不会自行恢复初始化；修复原因后需要 reload 或重启。`/health` 不经过框架，不检查数据库、Redis 或独立任务 Worker，也不能证明所有 HTTP Worker 均已就绪；生产应额外提供业务依赖探针并监控日志。

```bash
# 健康检查（应用初始化失败时返回 503）
curl http://localhost:8080/health

# 详细状态（仅调试模式）
curl http://localhost:8080/_status
```

## Changelog

查看 [CHANGELOG.md](CHANGELOG.md) 了解版本更新记录。

## License

MIT
