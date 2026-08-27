<?php

// Server 读取监听地址的 fixture：测试进程内直接 require（避免 webman 专属函数调用）
return [
    'webman' => [
        'handler' => 'Webman\Http\Server',
        'listen' => 'http://0.0.0.0:18787',
        'count' => 1,
    ],
];
