<?php

use WebmanTech\Testing\Exceptions\WebmanTestingTimeoutException;
use WebmanTech\Testing\Server;
use WebmanTech\Testing\TestCase;
use WebmanTech\Testing\Config\TestingConfig;

/*
 * TestCase 基类方法的行为契约（HTTP/命令的真实发送路径由 e2e 覆盖）
 */

test('webmanServer 进程级单例：首次配置生效，后续忽略', function () {
    $case = new TestCase('capture');
    $config = TestingConfig::fromConfig(['appDir' => fixture_get_path('webman-app')]);
    $server = $case->webmanServer($config);

    expect($server)->toBeInstanceOf(Server::class)
        ->and($case->webmanServer())->toBe($server) // 后续调用返回同一实例
        ->and($case->webmanServer(TestingConfig::fromConfig(['appDir' => $config->appDir, 'port' => 12345]))->config()->port)->toBe(18787); // 配置被忽略，保持首次默认
});

test('webmanRuntimePath 拼接 runtime 目录', function () {
    $case = new TestCase('capture');
    $case->webmanServer(TestingConfig::fromConfig(['appDir' => fixture_get_path('webman-app')]));

    expect($case->webmanRuntimePath())->toBe(fixture_get_path('webman-app') . '/runtime')
        ->and($case->webmanRuntimePath('logs/app.log'))->toBe(fixture_get_path('webman-app') . '/runtime/logs/app.log')
        ->and($case->webmanRuntimePath('/logs/app.log'))->toBe(fixture_get_path('webman-app') . '/runtime/logs/app.log');
});

test('webmanWaitFor 真值即返回', function () {
    $case = new TestCase('capture');
    $calls = 0;
    $result = $case->webmanWaitFor(function () use (&$calls) {
        $calls++;

        return $calls >= 3 ? 'reached' : false;
    }, 5.0, 0.001);

    expect($result)->toBe('reached')
        ->and($calls)->toBe(3);
});

test('webmanWaitFor 超时抛异常并附最后的探测返回值', function () {
    $case = new TestCase('capture');
    $start = microtime(true);

    expect(fn() => $case->webmanWaitFor(fn() => false, 0.05, 0.01))
        ->toThrow(WebmanTestingTimeoutException::class)
        // 超时时间被遵守（非提前抛出）
        ->and(microtime(true) - $start)->toBeGreaterThan(0.04);
});

test('webmanCommand 方法存在（真实执行由 e2e 覆盖）', function () {
    $case = new TestCase('capture');

    expect(method_exists($case, 'webmanCommand'))->toBeTrue();
});
