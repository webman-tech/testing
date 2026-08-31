<?php

// testing 配置（组件测试进程经 mock config() 读取；database 段由 InteractsWithDatabase::setUpDatabase() 消费）
return [
    'database' => [
        // phinx 迁移器：configFile 用绝对路径（fixture 不在测试进程 cwd 下）
        'phinx' => [
            'configFile' => __DIR__ . '/../phinx.php',
            'environment' => 'development',
        ],
        // 每测试数据隔离要清空的业务表
        'truncate' => ['users'],
    ],
];
