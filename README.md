# Laravel Workerman

使用 Workerman 加速 Laravel/Lumen 应用，常驻内存模式提升 5-20 倍性能。

## 版本要求

| 依赖 | 版本         |
|------|------------|
| PHP | ^7.2 \| ^8.0 |
| Laravel | ^6.0 |
| Lumen | ^6.0 |
| Workerman | ^4.0|

## 特性

- 🚀 **高性能**: 常驻内存，避免重复加载框架
- 🔄 **自动重启**: 达到最大请求数自动重启 Worker，防止内存泄漏
- 📁 **静态文件**: 内置静态文件服务，无需 Nginx
- 🛠 **易于集成**: 一键安装，开箱即用
- 📊 **性能统计**: 自动统计请求数、响应时间、内存使用
- 🔀 **双框架支持**: 同时兼容 Laravel 和 Lumen 框架

## 安装

```bash
# PHP >=8.1
composer -vvv require "code-gopher/laravel-workerman:^2.1"

# PHP >=7.0.0,<=7.4.33
composer -vvv require "code-gopher/laravel-workerman:^1.1"

# 确保你的composer.lock文件是在版本控制中
```

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

# 后台启动（守护进程，仅 Linux/Mac）
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
return [
    // 监听地址
    'host'         => env('WORKERMAN_HOST', '0.0.0.0'),

    // 监听端口
    'port'         => env('WORKERMAN_PORT', 8080),

    // Worker 进程数（Windows 只能为 1）
    'workers'      => env('WORKERMAN_WORKERS', 4),

    // 最大请求数（达到后 Worker 自动重启，防止内存泄漏）
    'max_requests' => env('WORKERMAN_MAX_REQUESTS', 10000),

    // 调试模式
    'debug'        => env('WORKERMAN_DEBUG', false),

    // 进程名称
    'name'         => env('WORKERMAN_NAME', 'laravel-workerman'),

    // 静态文件配置
    'static'       => [
        'enable' => true,
        'path'   => base_path('public'),
    ],

    // 日志配置
    'log'          => [
        'file' => storage_path('logs/workerman.log'),
    ],

    // 自定义清理器（每次请求结束后执行）
    'cleaners'     => [
        // App\Workerman\Cleaners\MyCleaner::class,
        // App\Workerman\Cleaners\AnotherCleaner::class,
    ],
];
```

## 自定义清理器

在 Workerman 常驻内存环境下，某些资源需要在每次请求结束后清理，以防止状态污染。

### 内置清理器

已内置以下清理器，自动执行：

- `GlobalVariableCleaner` - 清理 PHP 超全局变量
- `RequestInstanceCleaner` - 清理请求级别容器实例
- `FacadeCleaner` - 清理 Facade 缓存
- `SessionCleaner` - 保存并重置 Session
- `AuthCleaner` - 清理认证状态
- `CookieCleaner` - 清理 Cookie 队列
- `ValidatorCleaner` - 清理验证器实例
- `UrlGeneratorCleaner` - 清理 URL 生成器
- `DatabaseCleaner` - 清理数据库查询日志、回滚未提交事务

### 创建自定义清理器

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
│   └── CleanerInterface.php   # 清理器接口
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

### 2. 代码更新
- 修改代码后需要执行 `php workerman.php reload` 或重启服务

### 3. 静态变量
- 避免在静态变量中存储请求相关数据，可能导致数据污染

### 4. 单例模式
- 注意 Laravel/Lumen 容器中的单例在多次请求间共享

### 5. Windows 限制
- Windows 下只能使用单进程模式
- 不支持 `stop`、`restart`、`reload`、`status` 命令

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

内置健康检查端点，供负载均衡器和监控系统使用：

```bash
# 健康检查（始终可用）
curl http://localhost:8080/health

# 详细状态（仅调试模式）
curl http://localhost:8080/_status
```

## Changelog

查看 [CHANGELOG.md](CHANGELOG.md) 了解版本更新记录。

## License

MIT
