<?php

use support\Log;
use support\Request;
use Webman\Process\Http;

// 示例配置（按需修改）：本文件随 src_dir sync 覆盖到应用 config/process.php
return [
    'webman' => [
        'handler' => Http::class,
        // 业务端口保持官方默认 8787（用户现有部署零影响）；测试时由 phpunit.xml 注入
        // 应用自定义 env（示例 APP_PORT，变量名由应用自行定义）切换为测试端口：
        // 链路 = 测试进程 putenv → server 子进程继承 → 本文件读取；组件与应用同源读
        // config('process.webman.listen')，两端天然一致
        'listen' => 'http://0.0.0.0:' . (getenv('APP_PORT') ?: 8787),
        // 单进程，便于断言日志等共享状态
        'count' => 1,
        'user' => '',
        'group' => '',
        'reusePort' => false,
        'eventLoop' => '',
        'context' => [],
        'constructor' => [
            'requestClass' => Request::class,
            'logger' => Log::channel('default'),
        ],
    ],
    // 不启用 monitor（文件监控自动 reload）进程：e2e 下无意义且会干扰进程管理
];
