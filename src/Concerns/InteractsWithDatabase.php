<?php

declare(strict_types=1);

namespace WebmanTech\Testing\Concerns;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * 数据库断言（laravel InteractsWithDatabase 的对应物）
 *
 * 真实进程模式下测试进程直接连接被测应用的数据库（与 server 进程共享同一库）：
 * 测试开始前调用 setDatabaseConnection(new PDO(...)) 注入连接（DSN 可用
 * webmanRuntimePath() 定位 server 同款数据库文件，如 sqlite）。
 *
 * 与 laravel 的差异：不支持多连接（$connection 参数）与 seed/expectsDatabaseQueryCount
 * （依赖进程内容器），断言仅基于 SQL 查询。
 */
trait InteractsWithDatabase
{
    private ?PDO $webmanDatabaseConnection = null;

    /**
     * 注入数据库连接（如 sqlite: setDatabaseConnection(new PDO('sqlite:' . $this->webmanRuntimePath('app.sqlite'))))
     */
    public function setDatabaseConnection(PDO $pdo): static
    {
        $this->webmanDatabaseConnection = $pdo;

        return $this;
    }

    public function databaseConnection(): PDO
    {
        return $this->webmanDatabaseConnection
            ?? throw new RuntimeException('未设置数据库连接，请先调用 setDatabaseConnection(new PDO(...))');
    }

    /**
     * 断言表中存在满足 where 条件的记录
     */
    public function assertDatabaseHas(string $table, array $data): static
    {
        $count = $this->databaseCountWhere($table, $data);
        Assert::assertGreaterThan(
            0,
            $count,
            sprintf('数据库中不存在匹配记录: [%s] WHERE %s', $table, $this->whereDescription($data)),
        );

        return $this;
    }

    /**
     * 断言表中不存在满足 where 条件的记录
     */
    public function assertDatabaseMissing(string $table, array $data): static
    {
        $count = $this->databaseCountWhere($table, $data);
        Assert::assertSame(
            0,
            $count,
            sprintf('数据库中存在匹配记录: [%s] WHERE %s', $table, $this->whereDescription($data)),
        );

        return $this;
    }

    /**
     * 断言表中记录总数
     */
    public function assertDatabaseCount(string $table, int $count): static
    {
        $actual = $this->databaseTotalCount($table);
        Assert::assertSame(
            $count,
            $actual,
            sprintf('表 [%s] 记录数期望 %d，实际 %d', $table, $count, $actual),
        );

        return $this;
    }

    /**
     * 断言表为空
     */
    public function assertDatabaseEmpty(string $table): static
    {
        return $this->assertDatabaseCount($table, 0);
    }

    /**
     * 断言记录已软删除（deleted_at 列非空；列名按项目惯例固定为 deleted_at）
     */
    public function assertSoftDeleted(string $table, array $data = []): static
    {
        $count = $this->databaseCountWhere($table, $data, 'deleted_at IS NOT NULL');
        Assert::assertGreaterThan(
            0,
            $count,
            sprintf('数据库中不存在已软删除记录: [%s] WHERE %s', $table, $this->whereDescription($data)),
        );

        return $this;
    }

    /**
     * 断言记录未软删除（deleted_at 列为空）
     */
    public function assertNotSoftDeleted(string $table, array $data = []): static
    {
        $count = $this->databaseCountWhere($table, $data, 'deleted_at IS NULL');
        Assert::assertGreaterThan(
            0,
            $count,
            sprintf('数据库中不存在未软删除记录: [%s] WHERE %s', $table, $this->whereDescription($data)),
        );

        return $this;
    }

    private function databaseCountWhere(string $table, array $data, string $extraCondition = ''): int
    {
        $pdo = $this->databaseConnection();
        $this->assertSafeIdentifier($table);
        $conditions = [];
        foreach (array_keys($data) as $column) {
            $this->assertSafeIdentifier($column);
            $conditions[] = "`{$column}` = :{$column}";
        }
        if ($extraCondition !== '') {
            $conditions[] = $extraCondition;
        }

        $sql = 'SELECT COUNT(*) FROM `' . $table . '`';
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $stmt = $pdo->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException("SQL 预处理失败: {$sql}");
        }
        foreach ($data as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    private function databaseTotalCount(string $table): int
    {
        $this->assertSafeIdentifier($table);
        $pdo = $this->databaseConnection();
        $stmt = $pdo->query("SELECT COUNT(*) FROM `{$table}`");
        if ($stmt === false) {
            throw new RuntimeException("SQL 查询失败: SELECT COUNT(*) FROM `{$table}`");
        }

        return (int)$stmt->fetchColumn();
    }

    private function whereDescription(array $data): string
    {
        if ($data === []) {
            return '1=1';
        }

        return implode(' AND ', array_map(
            fn(string $key, mixed $value): string => "`{$key}` = " . var_export($value, true),
            array_keys($data),
            array_values($data),
        ));
    }

    private function assertSafeIdentifier(string $identifier): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException("非法的表名/列名（仅允许字母数字下划线）: {$identifier}");
        }
    }
}
