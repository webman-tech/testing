<?php

namespace app\controller;

use PDO;
use support\Request;
use support\Response;

/**
 * sqlite 数据演示：应用进程写库，测试进程 PDO 直连同一文件库断言（跨进程验证）
 *
 * 注意 :memory: 仅存在于 server 进程内，测试进程连不上，e2e 用文件库 runtime/e2e.sqlite，
 * 测试进程经 webmanRuntimePath('e2e.sqlite') 定位同源文件。
 */
class DataController
{
    private const DB_FILE = '/runtime/e2e.sqlite';

    private static function pdo(): PDO
    {
        $pdo = new PDO('sqlite:' . base_path() . self::DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            name TEXT NOT NULL,
            deleted_at TEXT NULL
        )');

        return $pdo;
    }

    public function create(Request $request): Response
    {
        $email = (string)$request->post('email');
        $name = (string)$request->post('name');
        if ($email === '' || $name === '') {
            return json(['error' => 'email/name required'])->withStatus(422);
        }

        $pdo = self::pdo();
        $stmt = $pdo->prepare('INSERT INTO users (email, name) VALUES (?, ?)');
        $stmt->execute([$email, $name]);

        return json(['id' => (int)$pdo->lastInsertId()])->withStatus(201);
    }

    public function index(Request $request): Response
    {
        $rows = self::pdo()->query('SELECT id, email, name FROM users WHERE deleted_at IS NULL ORDER BY id')
            ->fetchAll(PDO::FETCH_ASSOC);

        return json(['users' => $rows, 'count' => count($rows)]);
    }

    public function softDelete(Request $request, int $id): Response
    {
        $stmt = self::pdo()->prepare('UPDATE users SET deleted_at = ? WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([date('Y-m-d H:i:s'), $id]);

        return $stmt->rowCount() > 0
            ? json(['deleted' => true])
            : json(['error' => 'not found'])->withStatus(404);
    }

    public function reset(): Response
    {
        self::pdo()->exec('DELETE FROM users');

        return json(['reset' => true]);
    }
}
