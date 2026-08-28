<?php

namespace app\controller;

use support\Db;
use support\Request;
use support\Response;

/**
 * 数据库演示：经 webman/database（support\Db）操作 sqlite 文件库，测试进程 PDO 直连同一文件断言（跨进程验证）
 *
 * 连接由 config/database.php 经 env 控制（测试时 phpunit.xml 注入 DB_CONNECTION=sqlite 切文件库，
 * 与 process.php 的 APP_PORT 同一模式）；:memory: 仅存在于 server 进程内测试进程连不上，必须文件库。
 */
class DataController
{
    private static bool $tableEnsured = false;

    private static function ensureTable(): void
    {
        if (self::$tableEnsured) {
            return;
        }
        Db::statement('CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            name TEXT NOT NULL,
            deleted_at TEXT NULL
        )');
        self::$tableEnsured = true;
    }

    public function create(Request $request): Response
    {
        $email = (string)$request->post('email');
        $name = (string)$request->post('name');
        if ($email === '' || $name === '') {
            return json(['error' => 'email/name required'])->withStatus(422);
        }

        self::ensureTable();
        $id = Db::table('users')->insertGetId([
            'email' => $email,
            'name' => $name,
        ]);

        return json(['id' => $id])->withStatus(201);
    }

    public function index(Request $request): Response
    {
        self::ensureTable();
        $rows = Db::table('users')->whereNull('deleted_at')->orderBy('id')->get();

        return json(['users' => $rows, 'count' => count($rows)]);
    }

    public function softDelete(Request $request, int $id): Response
    {
        self::ensureTable();
        $affected = Db::table('users')
            ->whereNull('deleted_at')
            ->where('id', $id)
            ->update(['deleted_at' => date('Y-m-d H:i:s')]);

        return $affected > 0
            ? json(['deleted' => true])
            : json(['error' => 'not found'])->withStatus(404);
    }

    public function reset(): Response
    {
        self::ensureTable();
        Db::table('users')->delete();

        return json(['reset' => true]);
    }
}
