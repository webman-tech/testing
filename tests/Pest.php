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
        // runtime/ 已被 .gitignore 忽略
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

if (!function_exists('webman_mock_config_apply')) {
    /**
     * 把 mock 的配置数据灌入真实 Webman\Config（webman 的 config() 读取源）
     *
     * 组件 require-dev 已引入 webman/database（依赖 webman-framework）：单测进程的
     * config() 恒为 webman 真实实现（helpers.php 经 composer autoload.files 注册，
     * 先于任何测试代码加载），无法用同名函数替换。这里在 $GLOBALS 的 mock 数据
     * （webman_mock_app_dir / webman_mock_config_override）设置后，写入 Webman\Config：
     * 普通场景 clear + load(fixture 的 config/ 目录)；override 场景（整份配置替换，
     * 可能含闭包等不可序列化值）反射替换 config 数据并清空 configPath（避免 get
     * 未命中时 read 回退到旧目录文件）。
     */
    function webman_mock_config_apply(): void
    {
        \Webman\Config::clear();
        $override = $GLOBALS['webman_mock_config_override'] ?? null;
        if (is_array($override)) {
            $prop = new ReflectionProperty(\Webman\Config::class, 'config');
            $prop->setValue(null, $override);
            $pathProp = new ReflectionProperty(\Webman\Config::class, 'configPath');
            $pathProp->setValue(null, '');
            return;
        }
        $appDir = $GLOBALS['webman_mock_app_dir'] ?? null;
        $configDir = is_string($appDir) ? realpath($appDir . '/config') : false;
        if ($configDir !== false) {
            // 不能用 Webman\Config::load：其 loadFromDir 要求目录含 app.php（插件兼容
            // 逻辑），fixture 配置目录没有。按 mock 语义 glob 加载后反射写入，
            // configPath 一并设置（未命中 key 时 read 实时读文件，行为同 webman）
            $data = [];
            foreach (glob($configDir . '/*.php') ?: [] as $file) {
                $data[basename($file, '.php')] = require $file;
            }
            $prop = new ReflectionProperty(\Webman\Config::class, 'config');
            $prop->setValue(null, $data);
            $pathProp = new ReflectionProperty(\Webman\Config::class, 'configPath');
            $pathProp->setValue(null, $configDir);
        }
    }
}

if (!function_exists('webman_mock_use_app')) {
    /**
     * 设置 mock 应用的配置源并应用（各测试文件 beforeEach 一行完成）
     */
    function webman_mock_use_app(string $fixturePath, ?array $override = null): void
    {
        $GLOBALS['webman_mock_app_dir'] = fixture_get_path($fixturePath);
        $GLOBALS['webman_mock_config_override'] = $override;
        webman_mock_config_apply();
    }
}

if (!function_exists('database_support_db_reset')) {
    /**
     * 重置被测应用 Db 门面（support\Db，webman/database）的全局状态
     *
     * 组件 require-dev 已引入真实 webman/database（单测直接走被测应用同款 Db 门面，
     * 不再用替身类）。webman/database 的 Manager 是全局单例：连接缓存在协程 Context、
     * 连接池（DatabaseManager::$pools，static）与 Initializer 的 initialized 标记中；
     * 用例间需重置三者并按当前 mock 配置重新初始化，否则上一个用例 setPdo 的
     * :memory: 连接会泄漏到其他用例。被测应用的 config() 语义见 tests/bootstrap.php。
     */
    function database_support_db_reset(): void
    {
        // 触发 onDestroy 归还连接并清 Context 缓存（Webman\Context::destroy 为 public API）
        \Webman\Context::destroy();
        // 关闭并清空连接池（pools 为 protected static，反射访问）
        $pools = new ReflectionProperty(\Webman\Database\DatabaseManager::class, 'pools');
        foreach ($pools->getValue() ?: [] as $pool) {
            $pool->closeConnections();
        }
        $pools->setValue(null, []);
        // 重置 Initializer 标记并按当前 mock config 重新初始化 Manager
        $initialized = new ReflectionProperty(\Webman\Database\Initializer::class, 'initialized');
        $initialized->setValue(null, false);
        \Webman\Database\Initializer::init(config('database'));
    }
}
