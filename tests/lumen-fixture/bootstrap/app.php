<?php

declare(strict_types=1);

use Laravel\Lumen\Application;

$app = new Application(dirname(__DIR__));
$app->withFacades();
$app->withEloquent();
$app->configure('database');
$app->register(CodeGopher\LaravelWorkerman\WorkermanServiceProvider::class);
$app->router->group([], static function ($router): void {
    require dirname(__DIR__) . '/routes/web.php';
});

return $app;
