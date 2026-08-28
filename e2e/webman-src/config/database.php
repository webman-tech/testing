<?php

// 数据库连接 env 化（与 process.php 端口同一模式，变量名由应用自定）：
// - 业务运行时不设置环境变量 → 默认 mysql（模板同款连接）
// - 测试时由 phpunit.xml 注入 DB_CONNECTION=sqlite → 切换到文件库（runtime/e2e.sqlite），
//   与测试进程 webmanRuntimePath('e2e.sqlite') 同源定位（:memory: 跨进程不可见，必须文件库）
// - 链路：phpunit.xml → 测试进程环境 → server 子进程继承 → 本文件读取
return [
    'default' => getenv('DB_CONNECTION') ?: 'mysql',
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'your_database',
            'username' => 'your_username',
            'password' => 'your_password',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_general_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
            'options' => [
                PDO::ATTR_EMULATE_PREPARES => false, // Must be false for Swoole and Swow drivers.
            ],
            'pool' => [
                'max_connections' => 5,
                'min_connections' => 1,
                'wait_timeout' => 3,
                'idle_timeout' => 60,
                'heartbeat_interval' => 50,
            ],
        ],
        // 测试用文件库（webman/database 的 sqlite 连接）；文件路径与测试进程 webmanRuntimePath 同源
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => runtime_path() . '/e2e.sqlite',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ],
];
