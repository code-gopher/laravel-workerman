<?php

declare(strict_types=1);

namespace CodeGopher\LaravelWorkerman\Tests\Acceptance\Lifecycle;

use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * 独立记录启动和根路由分发，不依赖业务根路由的返回内容。
 */
final class BootMarker
{
    /** @var int 路由文件在当前 Worker 中的注册次数 */
    public static $registrations = 0;

    /** @var int 当前 Worker 匹配根路由的次数，包含可能的启动期伪请求 */
    public static $rootMatches = 0;

    /** @var string|null 最近一次根请求的显式验收标识 */
    public static $rootMarker;

    /** @var array<ScopedMarker> 保留最近的 scoped 对象，避免释放后对象编号被复用 */
    private static $scopedReferences = [];

    /**
     * 记录根路由的真实分发；正常启动不应触发此监听器。
     *
     * @param RouteMatched $event 路由匹配事件
     * @return void
     */
    public static function matched(RouteMatched $event): void
    {
        if ($event->route->uri() === '/') {
            self::$rootMatches++;
            self::$rootMarker = $event->request->header('X-Lifecycle-Marker');
        }
    }

    /**
     * 有界保留对象引用，确保本轮连续请求的编号具有区分能力。
     *
     * @param ScopedMarker $marker 当前请求的作用域对象
     * @return void
     */
    public static function retain(ScopedMarker $marker): void
    {
        self::$scopedReferences[] = $marker;
        // 测试只需连续比较八次；限制保留量，避免长期运行的 fixture 持续增长。
        if (count(self::$scopedReferences) > 64) {
            array_shift(self::$scopedReferences);
        }
    }
}

/**
 * 通过真实 scoped binding 捕获创建时的请求标识。
 */
final class ScopedMarker
{
    /** @var string|null 创建此作用域对象的请求标识 */
    public $marker;

    /**
     * 从容器注入的请求读取标识，而不是从全局变量重取。
     *
     * @param Request $request 当前容器请求
     */
    public function __construct(Request $request)
    {
        $this->marker = $request->query('marker');
    }
}

/**
 * 使用路由缓存敏感的构造函数注入，暴露旧 Controller 或旧 Request。
 */
final class RequestController
{
    /** @var Request 构造 Controller 时注入的请求，故意保留以检查跨请求残留 */
    private $request;

    /** @var ScopedMarker 构造 Controller 时注入的请求作用域对象 */
    private $scope;

    /**
     * 保留构造注入对象，不能在动作执行时重新读取来掩盖缓存问题。
     *
     * @param Request $request 构造时注入的请求
     * @param ScopedMarker $scope 构造时注入的作用域对象
     */
    public function __construct(Request $request, ScopedMarker $scope)
    {
        $this->request = $request;
        $this->scope = $scope;
    }

    /**
     * 对照方法注入和构造注入，检查同请求共享与跨请求隔离。
     *
     * @param Request $request 方法注入的当前请求
     * @param ScopedMarker $scope 方法注入的作用域对象
     * @return JsonResponse
     */
    public function __invoke(Request $request, ScopedMarker $scope): JsonResponse
    {
        BootMarker::retain($scope);

        return new JsonResponse([
            'worker' => getmypid(),
            'constructor_marker' => $this->request->query('marker'),
            'constructor_header' => $this->request->header('X-Lifecycle-Marker'),
            'action_marker' => $request->query('marker'),
            'action_header' => $request->header('X-Lifecycle-Marker'),
            'same_request' => $this->request === $request,
            'scope_marker' => $scope->marker,
            'scope_id' => spl_object_id($scope),
            'same_scope' => $this->scope === $scope && $scope === app(ScopedMarker::class),
        ]);
    }
}

/**
 * 读取 URL 生成结果和默认参数，避免通过反射状态代替真实行为。
 *
 * @return array{plain: string, named: string, defaults: array<string, mixed>}
 * @throws \Illuminate\Routing\Exceptions\UrlGenerationException 当默认路由参数丢失时
 */
function urlSnapshot(): array
{
    return [
        'plain' => URL::to('/acceptance/lifecycle/url-target'),
        'named' => URL::route('acceptance.lifecycle.url-target'),
        'defaults' => URL::getDefaultParameters(),
    ];
}

// 只在路由启动注册时执行；请求处理函数不重新注册，以暴露错误清理导致的丢失。
BootMarker::$registrations++;
app('events')->listen(RouteMatched::class, [BootMarker::class, 'matched']);
app()->scoped(ScopedMarker::class);
URL::defaults(['lifecycleTenant' => 'boot-tenant']);
Validator::extend('acceptance_lifecycle_boot',
    /**
     * 只有明确的合法值能通过，防止规则被空实现替代仍显示通过。
     *
     * @param string $attribute 被验证字段
     * @param mixed $value 字段值
     * @param array<string> $parameters 规则参数
     * @param \Illuminate\Validation\Validator $validator 当前验证器
     * @return bool
     */
    static function (string $attribute, $value, array $parameters, \Illuminate\Validation\Validator $validator): bool {
        return $value === 'lifecycle-allowed';
    },
    '启动期生命周期规则拒绝该值。'
);

Route::get('/acceptance/lifecycle/controller', RequestController::class);

Route::get('/acceptance/lifecycle/boot',
    /**
     * 返回独立计数器，既检查启动没有访问根路径，也允许显式请求作阳性对照。
     *
     * @return JsonResponse
     */
    static function (): JsonResponse {
        return new JsonResponse([
            'worker' => getmypid(),
            'registrations' => BootMarker::$registrations,
            'root_matches' => BootMarker::$rootMatches,
            'root_marker' => BootMarker::$rootMarker,
        ]);
    }
);

Route::get('/acceptance/lifecycle/url-target/{lifecycleTenant}',
    /**
     * 提供带必需参数的命名路由，使默认值丢失会产生真正的生成异常。
     *
     * @param string $lifecycleTenant 路由租户参数
     * @return JsonResponse
     */
    static function (string $lifecycleTenant): JsonResponse {
        return new JsonResponse(['worker' => getmypid(), 'tenant' => $lifecycleTenant]);
    }
)->name('acceptance.lifecycle.url-target');

Route::get('/acceptance/lifecycle/url',
    /**
     * 分别或组合污染 URL 状态，由下一个独立请求验证框架清理结果。
     *
     * @param Request $request 当前请求
     * @return JsonResponse
     * @throws \InvalidArgumentException 当污染模式不受支持时
     * @throws \Illuminate\Routing\Exceptions\UrlGenerationException 当默认参数已丢失时
     */
    static function (Request $request): JsonResponse {
        $mode = $request->query('mutate');
        if (!in_array($mode, [null, 'root', 'scheme', 'defaults', 'all'], true)) {
            throw new \InvalidArgumentException('不支持的生命周期 URL 污染模式');
        }

        $before = urlSnapshot();
        if ($mode === 'root' || $mode === 'all') {
            URL::forceRootUrl('http://lifecycle-forced.invalid');
        }
        if ($mode === 'scheme' || $mode === 'all') {
            // 与当前请求使用相反协议，即使将来改用 HTTPS 验收也能观察到变化。
            URL::forceScheme($request->isSecure() ? 'http' : 'https');
        }
        if ($mode === 'defaults' || $mode === 'all') {
            URL::defaults(['lifecycleTenant' => 'request-tenant', 'lifecycleOnly' => 'must-disappear']);
        }

        // 不在探针内恢复 URL 状态，否则无法验证请求结束后的生产清理器。
        return new JsonResponse([
            'worker' => getmypid(),
            'before' => $before,
            'after' => urlSnapshot(),
        ]);
    }
);

Route::get('/acceptance/lifecycle/validator',
    /**
     * 同时检查合法与非法输入，并返回自定义错误消息以证明规则真正执行。
     *
     * @return JsonResponse
     */
    static function (): JsonResponse {
        $rules = ['value' => ['required', 'acceptance_lifecycle_boot']];
        $valid = Validator::make(['value' => 'lifecycle-allowed'], $rules);
        $invalid = Validator::make(['value' => 'lifecycle-forbidden'], $rules);

        return new JsonResponse([
            'worker' => getmypid(),
            'registrations' => BootMarker::$registrations,
            'valid_passes' => $valid->passes(),
            'invalid_fails' => $invalid->fails(),
            'invalid_message' => $invalid->errors()->first('value'),
        ]);
    }
);
