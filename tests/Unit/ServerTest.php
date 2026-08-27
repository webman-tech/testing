<?php

use WebmanTech\Testing\Config\TestingConfig;
use WebmanTech\Testing\Server;

/*
 * Server 监听地址语义：组件不配置 host/port，而是与 webman 进程相同的读取方式
 * （config('process.webman.listen')，helpers 由被测应用 composer autoload.files 自动加载）
 * 读取 webman 进程的 listen 配置，天然与应用进程一致。
 * 应用侧 env 化切换端口的完整链路由 e2e 覆盖。
 */

beforeEach(function () {
    // 模拟 config() 的数据源：真实应用中由 webman-framework 的 helpers.php 提供（见 tests/Pest.php）
    $GLOBALS['webman_mock_app_dir'] = fixture_get_path('webman-app');
    $GLOBALS['webman_mock_config_override'] = null;
});

test('resolveListen 解析 listen 为 [scheme, host, port]，任意地址监听映射为本机回环', function () {
    expect(Server::resolveListen('http://0.0.0.0:18787'))->toBe(['http', '127.0.0.1', 18787])
        ->and(Server::resolveListen('http://127.0.0.1:8787'))->toBe(['http', '127.0.0.1', 8787])
        ->and(Server::resolveListen('https://example.com:8443'))->toBe(['https', 'example.com', 8443]);
});

test('resolveListen 未指定端口时默认 80', function () {
    expect(Server::resolveListen('http://127.0.0.1'))->toBe(['http', '127.0.0.1', 80]);
});

test('resolveListen 无法解析时抛异常', function () {
    expect(fn() => Server::resolveListen('not-a-url'))
        ->toThrow(InvalidArgumentException::class);
});

test('listen 配置缺失时抛可读异常', function () {
    // 模拟应用已加载配置但未配置 process.webman.listen
    // （baseUrl 未成功时无缓存，需先于正常读取的测试执行）
    $GLOBALS['webman_mock_config_override'] = ['app' => ['debug' => true]];
    $server = Server::instance(TestingConfig::fromConfig(['appDir' => fixture_get_path('webman-app')]));

    expect(fn() => $server->baseUrl())
        ->toThrow(InvalidArgumentException::class);
});

test('baseUrl 从应用 config/process.php 的 webman 进程 listen 读取', function () {
    $server = Server::instance(TestingConfig::fromConfig(['appDir' => fixture_get_path('webman-app')]));

    // fixture process.php: listen = http://0.0.0.0:18787（0.0.0.0 映射为 127.0.0.1）
    expect($server->baseUrl())->toBe('http://127.0.0.1:18787');
});

test('bootstrapWebman 在缺少 support/bootstrap.php 的应用抛可读异常', function () {
    // 本仓库单测环境无 webman 骨架（fixture 应用不含 support/bootstrap.php），仅验证可读异常路径；
    // 真实引导链路（配置完整加载/路由注册/组件可用）由 e2e 的 Unit/WebmanBootstrapTest 覆盖
    $server = Server::instance(TestingConfig::fromConfig(['appDir' => fixture_get_path('webman-app')]));

    expect(fn() => $server->bootstrapWebman())
        ->toThrow(RuntimeException::class)
        ->and(fn() => $server->bootstrapWebman())->toThrow(RuntimeException::class); // 引导失败不置幂等标志
});
