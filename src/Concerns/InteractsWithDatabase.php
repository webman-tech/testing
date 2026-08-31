<?php

declare(strict_types=1);

namespace WebmanTech\Testing\Concerns;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Assert;
use RuntimeException;
use WebmanTech\Testing\Config\TestingConfig;
use WebmanTech\Testing\Database\CallableMigrator;
use WebmanTech\Testing\Database\MigratorInterface;
use WebmanTech\Testing\Database\PhinxMigrator;
use WebmanTech\Testing\Database\TableCleaner;

/**
 * 数据库断言与就绪（对应 laravel InteractsWithDatabase + RefreshDatabase）
 *
 * 测试进程直接连接被测应用数据库（与 server 进程共享同一数据源）：
 * setUpDatabase() 一行完成「迁移 + 自动注入连接 + 数据隔离」，应用侧 setUp() 中调用；
 * 也可单独调 migrateDatabase() / truncateDatabaseTables()，或手动
 * setDatabaseConnection(new PDO(...)) 注入连接。
 *
 * 与 laravel 的差异：不支持多连接（$connection 参数）与 seed/expectsDatabaseQueryCount
 * （依赖进程内容器），断言仅基于 SQL 查询；数据隔离用清空业务表替代事务回滚
 * （跨进程无法事务）。
 */
trait InteractsWithDatabase
{
    private ?PDO $webmanDatabaseConnection = null;

    /** transaction 隔离标记（setUpDatabase 开启事务，tearDown/rollBackDatabase 回滚） */
    private bool $webmanDatabaseInTransaction = false;

    /** memory 隔离标记（setPdo 切换过被测应用 Db 连接，tearDown/restoreDatabaseConnection 恢复） */
    private bool $webmanDatabaseMemorySwitched = false;

    /** 进程级迁移标记（整个测试进程只迁移一次，跨测试实例共享；对应 laravel RefreshDatabaseState::$migrated） */
    private static bool $webmanDatabaseMigrated = false;

    /**
     * 数据库就绪（对应 laravel RefreshDatabase 的 setUp 语义，应用侧 setUp() 中一行调用）：
     * 迁移（进程级一次性）+ 自动注入连接 + 按 $isolation 隔离数据（默认 truncate 清空业务表）。
     *
     * $expect 可选安全校验：['sqlite' => 'testing.sqlite'] 表示期望 sqlite 驱动且库路径
     * 包含指定文件名——防测试 env 未注入时误连业务库并清空业务表。
     */
    public function setUpDatabase(array $expect = [], string $isolation = 'truncate'): static
    {
        // 未引导应用配置时（如 setUp 中先于任何 webmanServer 调用）主动加载被测应用
        // config 目录（幂等：config() 已非空时跳过），保证 config('database') 等可用
        TestingConfig::ensureConfigLoaded((string)getcwd());
        $this->assertExpectedDatabase($expect);

        return match ($isolation) {
            'transaction' => $this->setUpDatabaseWithTransaction(),
            'memory' => $this->setUpDatabaseInMemory(),
            default => $this->setUpDatabaseWithTruncate(),
        };
    }

    /**
     * 默认隔离（truncate）：迁移 + 注入连接 + 清空配置的业务表（跨进程 Feature 测试）
     */
    private function setUpDatabaseWithTruncate(): static
    {
        $this->migrateDatabase();
        $this->ensureDatabaseConnection();
        $this->truncateDatabaseTables();

        return $this;
    }

    /**
     * 事务隔离（transaction）：迁移 + 注入连接 + 开启事务，tearDown 自动回滚
     *
     * 仅适用于单进程数据库访问（unit 测试等不走 server 进程的场景）：测试进程内
     * Eloquent 与断言共用被测应用 Db 的同源连接，写入都在同一事务中，回滚可覆盖；
     * 跨进程 Feature 测试（server 进程写入）回滚无效，需用默认 truncate 隔离。
     */
    private function setUpDatabaseWithTransaction(): static
    {
        $this->migrateDatabase();
        $this->ensureDatabaseConnection();
        $this->databaseConnection()->beginTransaction();
        $this->webmanDatabaseInTransaction = true;

        return $this;
    }

    /**
     * 内存库隔离（memory，仅 sqlite）：每测试全新 :memory: 库 + 迁移，天然隔离
     *
     * 仅适用于单进程数据库访问（unit 测试场景）：经 setPdo 切换被测应用 Db 的
     * Eloquent 连接，写入/断言/迁移共用同一内存库；每测试都需重新迁移
     * （内存库随连接销毁），不受进程级一次性标记约束。
     */
    private function setUpDatabaseInMemory(): static
    {
        $pdo = new PDO('sqlite::memory:');
        /** @var \Illuminate\Database\Connection $connection */
        $connection = \support\Db::connection($this->webmanDatabaseDefaultName());
        $connection->setPdo($pdo);
        $this->webmanDatabaseMemorySwitched = true;
        $this->setDatabaseConnection($pdo);
        $this->createMigrator(connection: $pdo)->migrate();

        return $this;
    }

    /**
     * 注入数据库连接（未手动注入时）：取被测应用 Db（support\Db）的同源连接——
     * 与 Eloquent 共用同一 PDO，transaction/memory 隔离才可覆盖应用写入。
     * 组件默认被测应用已安装 webman/database；未安装时抛可读异常，
     * 可改手动 setDatabaseConnection(new PDO(...)) 直连（如 sqlite 文件库）。
     */
    private function ensureDatabaseConnection(): void
    {
        if ($this->webmanDatabaseConnection !== null) {
            return;
        }
        if (!class_exists(\support\Db::class)) {
            throw new RuntimeException(
                '未检测到被测应用的 webman/database（support\Db），无法自动注入测试数据库连接'
                . '（请安装 webman/database，或手动 setDatabaseConnection(new PDO(...))）',
            );
        }
        $this->setDatabaseConnection(
            \support\Db::connection($this->webmanDatabaseDefaultName())->getPdo(),
        );
    }

    private function webmanDatabaseDefaultName(): string
    {
        $database = config('database');

        return is_array($database) ? (string)($database['default'] ?? 'mysql') : 'mysql';
    }

    /**
     * 恢复 memory 模式切换的被测应用 Db 连接（组件 TestCase::tearDown 自动调用，也可手动调用）
     *
     * memory 模式经 setPdo 把被测应用 Db 的同源连接永久切到 :memory:，同进程后续
     * truncate/transaction 用例若复用该连接会静默连到内存库；恢复时 disconnect 丢弃
     * 内存库连接，下次 getPdo 按应用配置（config('database')）重新连接文件库。
     */
    public function restoreDatabaseConnection(): static
    {
        if (!$this->webmanDatabaseMemorySwitched) {
            return $this;
        }
        /** @var \Illuminate\Database\Connection $connection */
        $connection = \support\Db::connection($this->webmanDatabaseDefaultName());
        $connection->disconnect();
        // disconnect 后 getPdo 为 null，主动重连（经 reconnector 按应用配置重建文件库连接）
        $connection->reconnectIfMissingConnection();
        $this->webmanDatabaseMemorySwitched = false;

        return $this;
    }

    /**
     * 回滚 transaction 隔离开启的事务（组件 TestCase::tearDown 自动调用，也可手动调用）
     */
    public function rollBackDatabase(): static
    {
        if (!$this->webmanDatabaseInTransaction) {
            return $this;
        }
        if ($this->webmanDatabaseConnection !== null && $this->webmanDatabaseConnection->inTransaction()) {
            $this->webmanDatabaseConnection->rollBack();
        }
        $this->webmanDatabaseInTransaction = false;

        return $this;
    }

    /**
     * 执行数据库迁移（进程级一次性，重复调用自动跳过；迁移器取 testing 配置
     * database.migrator，默认 phinx——未安装 phinx 时抛可读异常）
     */
    public function migrateDatabase(): static
    {
        if (self::$webmanDatabaseMigrated) {
            return $this;
        }
        $this->createMigrator()->migrate();
        self::$webmanDatabaseMigrated = true;

        return $this;
    }

    /**
     * 清空业务表（每测试数据隔离；默认取 testing 配置 database.truncate，可传 $tables 覆盖）
     */
    public function truncateDatabaseTables(array $tables = []): static
    {
        $tables = $tables !== [] ? $tables : (array)($this->webmanDatabaseConfig()['truncate'] ?? []);
        if ($tables !== []) {
            (new TableCleaner($this->databaseConnection()))->clean($tables);
        }

        return $this;
    }

    /**
     * 注入数据库连接（如 sqlite 文件库：
     * setDatabaseConnection(new PDO('sqlite:' . $this->webmanRuntimePath('app.sqlite'))))
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

    private function webmanDatabaseConfig(): array
    {
        $testing = config('testing');

        return is_array($testing) ? (array)($testing['database'] ?? []) : [];
    }

    private function createMigrator(?PDO $connection = null): MigratorInterface
    {
        $migrator = $this->webmanDatabaseConfig()['migrator'] ?? null;
        if (is_string($migrator) && class_exists($migrator)) {
            $migrator = new $migrator();
        }
        if ($migrator instanceof MigratorInterface) {
            return $migrator;
        }
        if (is_callable($migrator)) {
            return new CallableMigrator($migrator);
        }
        if ($migrator !== null) {
            throw new RuntimeException('testing 配置 database.migrator 需为 MigratorInterface 实例/类名/callable');
        }
        // 默认 phinx
        if (!class_exists(\Phinx\Migration\Manager::class)) {
            throw new RuntimeException(
                '未检测到可用的数据库迁移器：请安装 robmorgan/phinx，'
                . '或在 testing 配置的 database.migrator 中提供自定义迁移器',
            );
        }
        $phinx = (array)($this->webmanDatabaseConfig()['phinx'] ?? []);
        $configFile = (string)($phinx['configFile'] ?? 'phinx.php');
        if (!str_starts_with($configFile, '/')) {
            $configFile = getcwd() . '/' . $configFile;
        }

        return new PhinxMigrator($configFile, (string)($phinx['environment'] ?? 'development'), $connection);
    }

    private function assertExpectedDatabase(array $expect): void
    {
        if ($expect === []) {
            return;
        }
        $database = config('database');
        if (!is_array($database)) {
            throw new RuntimeException('未检测到 webman 数据库配置（config(\'database\')），无法校验测试数据库');
        }
        $default = $database['default'] ?? 'mysql';
        $connection = is_array($database['connections'][$default] ?? null) ? $database['connections'][$default] : [];
        foreach ($expect as $driver => $needle) {
            $actualDriver = (string)($connection['driver'] ?? $default);
            if ($actualDriver !== $driver) {
                throw new RuntimeException(sprintf(
                    '测试数据库驱动期望 %s，实际 %s（检查 phpunit.xml 注入的数据库 env 是否生效）',
                    $driver,
                    $actualDriver,
                ));
            }
            if ($needle !== null && !str_contains((string)($connection['database'] ?? ''), (string)$needle)) {
                throw new RuntimeException(sprintf(
                    '测试数据库路径期望包含 %s，实际 %s（防误连业务库）',
                    (string)$needle,
                    (string)($connection['database'] ?? ''),
                ));
            }
        }
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
     * 断言记录已软删除（deleted_at 列非空）
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
