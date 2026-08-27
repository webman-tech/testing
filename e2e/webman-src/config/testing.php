<?php

// webman-tech/testing 组件配置（应用侧维护，测试进程与 webman 应用共享同一文件：
// webman 侧经 config('testing') 读取，测试进程内直接 require）。
// 请使用自包含写法（避免 base_path() 等 webman 专属函数，可用 getcwd() 代替）；
// 键名与 TestingConfig::fromConfig 一致，优先级：显式传参 > 本文件 > 默认值。
return [
    // PSR-18 HTTP 客户端（自动发现的 guzzle）构造参数；http_errors 恒为 false 不可配置。
    // 端口未配置时两端（config/process.php 与 TestingConfig）默认一致为 18787。
    'httpClient' => [
        'timeout' => 5,
        'connect_timeout' => 1,
    ],
];
