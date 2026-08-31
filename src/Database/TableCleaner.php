<?php

declare(strict_types=1);

namespace WebmanTech\Testing\Database;

use InvalidArgumentException;
use PDO;

/**
 * 测试数据隔离：清空业务表（对应 laravel RefreshDatabase 的事务回滚/truncate 语义）
 *
 * 真实进程模式下跨进程无法使用事务回滚，改为每次测试前清空配置的业务表；
 * sqlite 不支持 TRUNCATE，用 DELETE 并顺带重置自增（sqlite_sequence）。
 */
final class TableCleaner
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @param string[] $tables 表名白名单（须符合标识符规范，防注入）
     */
    public function clean(array $tables): void
    {
        if ($tables === []) {
            return;
        }
        $isSqlite = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $hasSequenceTable = $isSqlite && $this->hasSqliteSequenceTable();

        foreach ($tables as $table) {
            $this->assertSafeIdentifier($table);
            $this->pdo->exec("DELETE FROM `{$table}`");
            if ($hasSequenceTable) {
                $this->pdo->exec("DELETE FROM `sqlite_sequence` WHERE `name` = '{$table}'");
            }
        }
    }

    private function hasSqliteSequenceTable(): bool
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM `sqlite_master` WHERE `type` = 'table' AND `name` = 'sqlite_sequence'");
        if ($stmt === false) {
            return false;
        }

        return (int)$stmt->fetchColumn() > 0;
    }

    private function assertSafeIdentifier(string $identifier): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException("非法的表名（仅允许字母数字下划线）: {$identifier}");
        }
    }
}
