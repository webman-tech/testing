<?php

declare(strict_types=1);

/*
 * 组件自身单测的 Pest 配置与公共 helper。
 *
 * 单测不启动真实 webman 进程（那是 e2e/集成测试的职责，见 AGENTS.md），
 * 这里只验证组件在进程内的行为：TestResponse 断言语义、配置传导、请求组装、数据库断言等。
 */

if (!function_exists('fixture_get_path')) {
    /**
     * 获取 tests/Fixtures 下的 fixture 绝对路径
     */
    function fixture_get_path(string $path): string
    {
        return __DIR__ . '/Fixtures/' . ltrim($path, '/');
    }
}

if (!function_exists('config')) {
    /**
     * 模拟被测应用（webman 项目）的 config() 函数
     *
     * 真实应用中该函数由 webman-framework 的 helpers.php 提供（composer.json autoload.files
     * 注册），测试进程加载被测应用 vendor/autoload.php 时自动就绪（见 Server::readListen()）。
     * 本仓库单测环境无 webman 框架，这里按 webman Config 语义模拟：读取
     * $GLOBALS['webman_mock_app_dir'] 指向应用的 config/ 目录（文件名 = 一级 key，支持点分
     * 路径）；$GLOBALS['webman_mock_config_override'] 为数组时直接作为全部配置（异常路径测试用）。
     */
    function config(?string $key = null, mixed $default = null): mixed
    {
        $override = $GLOBALS['webman_mock_config_override'] ?? null;
        if (is_array($override)) {
            $data = $override;
        } else {
            $appDir = $GLOBALS['webman_mock_app_dir'] ?? null;
            $configDir = is_string($appDir) ? realpath($appDir . '/config') : false;
            if ($configDir === false) {
                return $key === null ? [] : $default;
            }
            static $cache = [];
            $data = $cache[$configDir] ??= (static function () use ($configDir): array {
                $result = [];
                foreach (glob($configDir . '/*.php') ?: [] as $file) {
                    $result[basename($file, '.php')] = require $file;
                }

                return $result;
            })();
        }
        if ($key === null) {
            return $data;
        }
        $value = $data;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
