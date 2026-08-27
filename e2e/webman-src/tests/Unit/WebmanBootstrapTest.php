<?php

use WebmanTech\Testing\Server;

// 非 HTTP 测试场景（tests/Unit，未绑定 TestCase 基类）：直接经 Server 单例引导
// webman 环境后使用 webman 组件，与 worker 进程内一致的完整配置/路由/中间件/bootstrap 初始化

test('bootstrapWebman 后测试进程内 webman 组件与配置完整可用', function () {
    Server::instance()->bootstrapWebman();

    // 配置完整加载（与 webman worker 进程内同款 loadAllConfig：process 亦可用）
    expect(config('process.webman.listen'))->toBe('http://0.0.0.0:18787')
        ->and(config('app.debug'))->toBeBool();

    // 时区与配置一致（bootstrap 设置 config/app.php 的 default_timezone 生效）
    expect(date_default_timezone_get())->toBe(config('app.default_timezone'));

    // 组件可用：support\Log 经 bootstrap 初始化（同 worker 进程内）
    expect(\support\Log::channel('default'))->toBeInstanceOf(\Monolog\Logger::class);

    // 路由已注册（Route::load 加载应用 route.php，与 worker 进程内一致）
    expect(\Webman\Route::getRoutes())->not->toBeEmpty();
});

test('bootstrapWebman 幂等且可重复调用', function () {
    Server::instance()->bootstrapWebman();
    // 重复调用不抛错（Server 侧标志保证只引导一次，避免路由重复注册）
    expect(fn() => Server::instance()->bootstrapWebman())->not->toThrow(Exception::class);
});
