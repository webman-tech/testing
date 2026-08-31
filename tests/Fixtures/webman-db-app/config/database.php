<?php

// webman 数据库配置（模拟被测应用；setUpDatabase 自动注入连接的数据源）
return [
    'default' => 'sqlite',
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => __DIR__ . '/../runtime/db.sqlite',
            'prefix' => '',
        ],
    ],
];
