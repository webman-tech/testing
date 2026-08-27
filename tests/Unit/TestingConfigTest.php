<?php

use WebmanTech\Testing\Config\TestingConfig;

/*
 * TestingConfig 的构造、校验与配置传导（显式传参 > webman 配置文件 > 默认值）。
 */

beforeEach(function () {
    // 模拟 config() 的数据源：真实应用中由 webman-framework 的 helpers.php 提供（见 tests/Pest.php）
    $GLOBALS['webman_mock_app_dir'] = fixture_get_path('webman-app');
    $GLOBALS['webman_mock_config_override'] = null;
});

test('fromConfig 默认值与入口文件校验', function () {
    $appDir = fixture_get_path('webman-app');
    $config = TestingConfig::fromConfig(['appDir' => $appDir]);

    expect($config->appDir)->toBe($appDir)
        ->and($config->phpBinary)->toBe(PHP_BINARY)
        ->and($config->entryFile)->toBe('start.php')
        ->and($config->serverEnv)->toBe([])
        ->and($config->stdoutReady)->toBe('Start success')
        ->and($config->startTimeout)->toBe(30.0)
        ->and($config->processTimeout)->toBe(600)
        ->and($config->stopTimeout)->toBe(10.0)
        ->and($config->command)->toBe('webman')
        // httpClient 与 guzzle 自动发现一致（timeout/connect_timeout 合并默认）
        ->and($config->httpClient)->toBe(['timeout' => 10.0, 'connect_timeout' => 2.0]);

    // appDir 下无入口文件时抛含指引的异常
    expect(fn() => TestingConfig::fromConfig(['appDir' => fixture_get_path('Testing')]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => TestingConfig::fromConfig(['appDir' => $appDir, 'entryFile' => 'other.php']))
        ->toThrow(InvalidArgumentException::class);
});

test('webman 配置文件（config/testing.php）自动读取', function () {
    $appDir = fixture_get_path('webman-config-app');
    $GLOBALS['webman_mock_app_dir'] = $appDir;
    $config = TestingConfig::fromConfig(['appDir' => $appDir]);

    expect($config->stdoutReady)->toBe('Server ready')
        ->and($config->serverEnv)->toBe(['APP_ENV' => 'testing'])
        ->and($config->stopTimeout)->toBe(5.0)
        ->and($config->processTimeout)->toBe(120)
        ->and($config->httpClient)->toBe(['timeout' => 5, 'connect_timeout' => 1]);
});

test('配置源优先级：显式传参 > webman 配置文件 > 默认值', function () {
    $appDir = fixture_get_path('webman-config-app');
    $GLOBALS['webman_mock_app_dir'] = $appDir;

    // 显式传参 > 配置文件
    expect(TestingConfig::fromConfig(['appDir' => $appDir, 'stdoutReady' => 'explicit'])->stdoutReady)->toBe('explicit')
        // 配置文件 > 默认值
        ->and(TestingConfig::fromConfig(['appDir' => $appDir])->stdoutReady)->toBe('Server ready');

    // 无配置文件时用默认值（模拟应用已加载配置但未提供 testing 配置）
    $GLOBALS['webman_mock_config_override'] = ['app' => ['debug' => true]];
    expect(TestingConfig::fromConfig(['appDir' => fixture_get_path('webman-app')])->stdoutReady)->toBe('Start success');
});

test('fromConfig 覆盖各项配置', function () {
    $config = TestingConfig::fromConfig([
        'appDir' => fixture_get_path('webman-app'),
        'entryFile' => 'start.php',
        'serverEnv' => ['APP_ENV' => 'testing', 'FOO' => 'bar'],
        'stdoutReady' => 'Server ready',
        'startTimeout' => 5.0,
        'processTimeout' => 120,
        'stopTimeout' => 3.0,
        'command' => 'my-webman',
    ]);

    expect($config->serverEnv)->toBe(['APP_ENV' => 'testing', 'FOO' => 'bar'])
        ->and($config->stdoutReady)->toBe('Server ready')
        ->and($config->startTimeout)->toBe(5.0)
        ->and($config->processTimeout)->toBe(120)
        ->and($config->stopTimeout)->toBe(3.0)
        ->and($config->command)->toBe('my-webman')
        // 未配置 httpClient 时保持默认
        ->and($config->httpClient)->toBe(['timeout' => 10.0, 'connect_timeout' => 2.0]);
});

test('fromConfig 覆盖 httpClient（与默认值合并）', function () {
    $config = TestingConfig::fromConfig([
        'appDir' => fixture_get_path('webman-app'),
        'httpClient' => ['timeout' => 6],
    ]);

    // 与默认值 array_merge（http_errors 恒 false 由 HttpClientFactory 保证）
    expect($config->httpClient)->toBe(['timeout' => 6, 'connect_timeout' => 2.0]);
});
