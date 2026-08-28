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

if (!function_exists('e2e_tmp_dir')) {
    /**
     * 创建并登记 e2e 编排器单测的临时目录（afterEach 自动清理）
     */
    function e2e_tmp_dir(string $name = 'tmp'): string
    {
        // runtime/ 已被 .gitignore 忽略（与 phpstan 的 runtime/phpstan 同源）
        // realpath 规范化路径（去除 tests/.. 等未规范化段），与 Console 侧 dirname(realpath($configFile)) 保持一致
        $base = realpath(__DIR__ . '/../runtime') ?: __DIR__ . '/../runtime';
        $dir = $base . '/e2e-tmp/' . $name . '-' . uniqid();
        mkdir($dir, 0755, true);
        $GLOBALS['e2e_tmp_dirs'][] = $dir;

        return $dir;
    }
}

if (!function_exists('e2e_installer_skeleton')) {
    /**
     * 预建/重建 target 骨架（模拟 composer create-project 产物）
     *
     * install 流程测试的 runner stub 不执行真实 create-project，但目录删除是 PHP 原生
     * 实现（真实执行），需在命令序列中模拟 create-project 副作用，保证后续 patch 有文件可读。
     */
    function e2e_installer_skeleton(string $baseDir): void
    {
        mkdir($baseDir . '/target', 0755, true);
        file_put_contents($baseDir . '/target/composer.json', json_encode([
            'name' => 'skeleton/app',
            'require' => ['php' => '^8.2', 'monolog/monolog' => '^2.0'],
            'config' => ['allow-plugins' => ['other/plugin' => true]],
        ], JSON_PRETTY_PRINT));
    }
}

// 注意：不能直接用全局函数 afterEach()——Pest 3 中全局函数 hooks 按「调用文件路径」精确匹配
// 测试文件（AfterEachRepository::get），tests/Pest.php 自身不是测试文件，注册的 hook 永不执行；
// 必须经 uses()->afterEach()->in(__DIR__) 把 hook 传播到 tests 目录下的所有测试类。
uses()->afterEach(function () {
    // 清理 e2e_tmp_dir() 创建的临时目录（其他测试未登记，不受影响）
    foreach ($GLOBALS['e2e_tmp_dirs'] ?? [] as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir((string)$item);
            } else {
                unlink((string)$item);
            }
        }
        rmdir($dir);
    }
    $GLOBALS['e2e_tmp_dirs'] = [];
})->in(__DIR__);

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
