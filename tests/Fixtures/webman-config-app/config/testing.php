<?php

// 测试用配置文件（config/testing.php）：键名与 TestingConfig::fromConfig 一致，
// 配置在测试进程内直接 require 执行，避免 base_path() 等 webman 专属函数
return [
    'serverEnv' => ['APP_ENV' => 'testing'],
    'stdoutReady' => 'Server ready',
    'stopTimeout' => 5.0,
    'processTimeout' => 120,
    'httpClient' => [
        'timeout' => 5,
        'connect_timeout' => 1,
    ],
];
