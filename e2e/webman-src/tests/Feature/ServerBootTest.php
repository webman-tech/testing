<?php

// 真实 webman 进程启动/健康检查/停止的基础验证

test('server 可启动并响应 health', function () {
    $this->get('/health')->assertOk()->assertJson(['status' => 'ok']);
});

test('未注册路由返回 404', function () {
    $this->get('/not-exists-route')->assertNotFound();
});

test('重定向默认不跟随，可断言 302 与 Location', function () {
    $this->get('/redirect')
        ->assertStatus(302)
        ->assertLocation('/health');
});

test('followingRedirects 跟随重定向并断言最终响应', function () {
    // PSR-18 无自动重定向，跟随逻辑为组件手动实现（e2e 覆盖）
    $this->followingRedirects()->get('/redirect')
        ->assertOk()
        ->assertJson(['status' => 'ok']);
});

test('303 重定向将 POST 转为 GET', function () {
    $this->followingRedirects()->post('/redirect-post')
        ->assertOk()
        ->assertJson(['status' => 'ok']);
});

test('监听地址由应用 config/process.php 驱动（组件与 webman 同一读取方式，业务端口不受影响）', function () {
    // 组件不配置 host/port：以与 webman 相同的方式 require 应用 config/process.php 读取
    // webman 进程的 listen；phpunit.xml 注入 APP_PORT=18787 → process.php 的 getenv 生效，
    // 组件与应用进程读到同一地址（0.0.0.0 监听映射为本机回环 127.0.0.1）
    expect($this->webmanServer()->baseUrl())->toBe('http://127.0.0.1:18787');
    // 链路闭环：phpunit.xml 设置 → 测试进程环境 → server 子进程继承 → process.php 读取
    $this->get('/env/app-port')->assertOk()->assertJson(['port' => '18787']);
});

test('应用侧 config/testing.php 的 httpClient 参数生效', function () {
    // 组件自动读取被测应用的 config/testing.php（与 webman 侧 config('testing') 同源）；
    // 自动发现的 guzzle 客户端按配置构造（默认 timeout=10/connect_timeout=2，此处配置为 5/1）
    $client = $this->webmanServer()->client();
    expect($client)->toBeInstanceOf(GuzzleHttp\Client::class)
        ->and($client->getConfig('timeout'))->toBe(5)
        ->and($client->getConfig('connect_timeout'))->toBe(1)
        // http_errors 恒为 false，保证 4xx/5xx 交由断言层处理
        ->and($client->getConfig('http_errors'))->toBeFalse();
});
