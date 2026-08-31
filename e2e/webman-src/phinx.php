<?php

// phinx 迁移配置（测试进程内由 webman-tech/testing 的 PhinxMigrator 执行迁移；
// 连接与 config/database.php 同源：env 化切换 sqlite/mysql，与端口 APP_PORT 同一模式）
$database = require __DIR__ . '/config/database.php';
$connectionName = $database['default'];
$connection = $database['connections'][$connectionName];

$development = [
    'adapter' => $connection['driver'],
    'name' => $connection['database'],
    'host' => $connection['host'] ?? null,
    'user' => $connection['username'] ?? null,
    'pass' => $connection['password'] ?? null,
    'port' => $connection['port'] ?? null,
    'charset' => $connection['charset'] ?? null,
];
if ($connection['driver'] === 'sqlite') {
    // phinx 0.16 sqlite adapter 会把 name 追加 .sqlite3 后缀，导致迁移库与应用
    // 连接文件不一致；直接传 PDO 保证同一文件（runtime/e2e.sqlite）
    $development = [
        'adapter' => 'sqlite',
        'connection' => new PDO('sqlite:' . $connection['database']),
    ];
}

return [
    'paths' => [
        'migrations' => __DIR__ . '/resource/database/migrations',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'development' => $development,
    ],
    'version_order' => 'creation',
];
