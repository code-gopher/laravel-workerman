<?php

declare(strict_types=1);

namespace CodeGopher\LaravelWorkerman\Tests;

use CodeGopher\LaravelWorkerman\Cleaners\AuthCleaner;
use CodeGopher\LaravelWorkerman\Cleaners\SessionCleaner;
use CodeGopher\LaravelWorkerman\Cleaners\ValidatorCleaner;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\GenericUser;
use Illuminate\Auth\SessionGuard;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Manager;
use PHPUnit\Framework\TestCase;

/**
 * 覆盖常驻进程中会话、认证和规则注册表的请求边界。
 */
final class LifecycleTest extends TestCase
{
    /**
     * 已由中间件保存的 flash 必须存活到下一次请求。
     *
     * @return void
     */
    public function testSavedFlashIsNotAgedTwice(): void
    {
        $app = new Container();
        $app->instance('config', new Repository());
        $store = new Store('test', new ArraySessionHandler(120));
        $store->start();
        $store->flash('notice', 'visible-once');
        $id = $store->getId();
        $store->save();
        $manager = $this->getMockBuilder(Manager::class)->setConstructorArgs([$app])->onlyMethods(['getDefaultDriver', 'getDrivers'])->getMock();
        $manager->method('getDrivers')->willReturn(['array' => $store]);
        $app->instance('session', $manager);

        (new SessionCleaner())->clean($app);
        $store->setId($id);
        $store->start();
        self::assertSame('visible-once', $store->get('notice'));
        $store->save();
        $store->flush();
        $store->start();
        self::assertNull($store->get('notice'));
    }

    /**
     * logout 标志不得阻止下一位客户端读取自己的有效会话。
     *
     * @return void
     */
    public function testSessionGuardFlagsAreResetWithoutRecreatingGuard(): void
    {
        $app = new Container();
        $app->instance('config', new Repository(['auth' => ['defaults' => ['guard' => 'web']]]));
        $user = new GenericUser(['id' => 42]);
        $provider = $this->createMock(UserProvider::class);
        $provider->method('retrieveById')->willReturn($user);
        $store = new Store('test', new ArraySessionHandler(120));
        $guard = new SessionGuard('web', $provider, $store, new Request());
        $auth = new AuthManager($app);
        $property = new \ReflectionProperty($auth, 'guards');
        $flag = new \ReflectionProperty($guard, 'loggedOut');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
            $flag->setAccessible(true);
        }
        $property->setValue($auth, ['web' => $guard]);
        $flag->setValue($guard, true);
        $app->instance('auth', $auth);

        (new AuthCleaner())->clean($app);
        $store->put($guard->getName(), 42);
        self::assertSame($user, $guard->user());
        self::assertSame($guard, $auth->guard('web'));
    }

    /**
     * 自定义验证规则属于应用级定义，不能在第一个请求后丢失。
     *
     * @return void
     */
    public function testValidatorFactoryRegistrationSurvivesCleanup(): void
    {
        $app = new Container();
        $factory = new \stdClass();
        $app->instance('validator', $factory);
        (new ValidatorCleaner())->clean($app);
        self::assertSame($factory, $app->make('validator'));
    }
}
