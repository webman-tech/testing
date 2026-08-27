<?php

use app\process\Crontab;
use app\process\Http;
use support\Log;
use support\Request;

global $argv;

return [
    'webman' => [
        'handler' => Http::class,
        // 业务端口保持官方默认 8787（用户现有部署零影响）；测试时 phpunit.xml 设置
        // APP_PORT 环境变量（测试进程环境 → server 子进程继承），此处切换为测试端口；
        // 组件与 webman 同一读取方式（config('process.webman.listen')），两端天然一致
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
            // logger/appPath/publicPath 由 webman 专属函数构造（Log::channel/app_path/public_path），
            // 该文件由 webman 进程加载；组件不 require 本文件——测试进程经 composer
            // autoload.files 自动加载 helpers 后直接读 config('process.webman.listen')
            'logger' => Log::channel('default'),
            'appPath' => app_path(),
            'publicPath' => public_path(),
        ],
    ],
    // 副作用演示：每秒写 runtime/e2e-crontab.log（webmanWaitFor 轮询等待）
    'crontab' => [
        'handler' => Crontab::class,
    ],
    // 不启用 monitor（文件监控自动 reload）进程：e2e 下无意义且会干扰进程管理
];
