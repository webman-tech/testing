<?php

use app\controller\AuthController;
use app\controller\DataController;
use app\middleware\TokenAuthMiddleware;
use Webman\Route;

Route::get('/health', fn() => json(['status' => 'ok']));

// 端口链路验证：应用进程侧读取测试进程继承的 APP_PORT（phpunit.xml 设置）
// （业务端口默认 8787 不受影响，仅测试时经环境变量切换为测试端口）
Route::get('/env/app-port', fn() => json(['port' => (string)getenv('APP_PORT')]));

// 数据库链路验证：应用进程侧读取 config/database.php 的 default（phpunit.xml 注入
// DB_CONNECTION=sqlite 时切换到 sqlite 文件库，业务默认 mysql 不受影响）
Route::get('/env/db-connection', fn() => json(['connection' => config('database.default')]));

// 重定向跟随/不跟随双路径（followingRedirects 断言最终响应；默认断言 302 + Location）
Route::get('/redirect', fn() => redirect('/health'));
// 303 语义：POST 跟随重定向时转为 GET（组件手动实现 guzzle 语义的覆盖点）
Route::post('/redirect-post', fn() => redirect('/health', 303));

Route::group('/auth', function () {
    Route::post('/login', [AuthController::class, 'login']);
    // 受保护路由：认证在中间件内完成，失败返回 401
    Route::get('/user', [AuthController::class, 'user'])->middleware([TokenAuthMiddleware::class]);
});

Route::group('/data', function () {
    Route::post('/users', [DataController::class, 'create']);
    Route::get('/users', [DataController::class, 'index']);
    Route::delete('/users/{id}', [DataController::class, 'softDelete']);
    // 跨进程无容器魔法：数据重置推荐应用侧提供 reset 端点（e2e 覆盖该模式）
    Route::post('/reset', [DataController::class, 'reset']);
});
