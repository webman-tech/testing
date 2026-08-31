<?php

// phinx 迁移配置（PhinxMigrator 复用；与 config/database.php 指向同一 sqlite 文件）
// 注意：不能只配 name——phinx 0.16 sqlite adapter 会把 name 追加 .sqlite3 后缀，
// 导致迁移库与 config/database.php 的连接文件不一致；用 connection（PDO）直传最稳。
return [
    'paths' => [
        'migrations' => __DIR__ . '/migrations',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'development' => [
            'adapter' => 'sqlite',
            'connection' => new PDO('sqlite:' . __DIR__ . '/runtime/db.sqlite'),
        ],
    ],
    'version_order' => 'creation',
];
