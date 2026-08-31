<?php

use WebmanTech\Testing\Database\PhinxMigrator;
use WebmanTech\Testing\TestCase;

/*
 * InteractsWithDatabase 的数据库就绪能力（setUpDatabase：迁移 + 自动注入连接 + 数据隔离）
 *
 * 基于 fixture webman-db-app（真实 phinx 执行 sqlite 文件库迁移，验证完整链路）；
 * 一次性迁移的 static 标记在 beforeEach 中经反射重置，保证各用例独立。
 */

class DatabaseSetupTestCase extends TestCase
{
    public function runTearDown(): void
    {
        $this->tearDown();
    }
}

function database_setup_fixture_dir(): string
{
    return fixture_get_path('webman-db-app');
}

function database_setup_reset(): void
{
    // 重置进程级迁移标记（trait 的 static 属性挂在 use 它的 TestCase 上）
    $prop = new ReflectionProperty(TestCase::class, 'webmanDatabaseMigrated');
    $prop->setValue(null, false);
    // 重建 fixture 库文件（保证各用例从空库开始）
    $dbDir = database_setup_fixture_dir() . '/runtime';
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0755, true);
    }
    $dbFile = $dbDir . '/db.sqlite';
    if (is_file($dbFile)) {
        unlink($dbFile);
    }
    // 重建空文件：illuminate 的 SQLiteConnector（support\Db 连接创建）要求库文件
    // 已存在，不自动创建（区别于裸 new PDO）
    touch($dbFile);
}

beforeEach(function () {
    webman_mock_use_app('webman-db-app');
    database_setup_reset();
    // 重置真实 support\Db（webman/database）全局状态并重新初始化（读当前 mock 配置）
    database_support_db_reset();
});

test('setUpDatabase 执行迁移、自动注入连接并清空配置的业务表', function () {
    $case = new DatabaseSetupTestCase('capture');
    $case->setUpDatabase();

    // 连接已自动注入（从 config('database') 构建）
    expect($case->databaseConnection())->toBeInstanceOf(PDO::class)
        // 迁移生效：phinxlog 记录 + users 表存在
        ->and($case->assertDatabaseCount('phinxlog', 1))
        ->and($case->assertDatabaseCount('users', 0));
});

test('migrateDatabase 进程级一次性（static 标记跨实例共享）', function () {
    $case = new DatabaseSetupTestCase('capture');
    $case->setUpDatabase();

    $prop = new ReflectionProperty(TestCase::class, 'webmanDatabaseMigrated');
    expect($prop->getValue())->toBeTrue()
        // 再次调用直接跳过（不抛异常、不重复迁移）
        ->and($case->migrateDatabase())->toBeInstanceOf(TestCase::class)
        ->and($case->assertDatabaseCount('phinxlog', 1));
});

test('truncateDatabaseTables 清空配置的业务表', function () {
    $case = new DatabaseSetupTestCase('capture');
    $case->setUpDatabase();
    $case->databaseConnection()->exec("INSERT INTO `users` (`name`) VALUES ('dirty')");
    expect($case->assertDatabaseCount('users', 1));

    $case->truncateDatabaseTables();

    expect($case->assertDatabaseCount('users', 0));
});

test('truncateDatabaseTables 支持传参覆盖配置', function () {
    $case = new DatabaseSetupTestCase('capture');
    $case->setUpDatabase();
    $case->databaseConnection()->exec("INSERT INTO `users` (`name`) VALUES ('dirty')");

    $case->truncateDatabaseTables(['users']);

    expect($case->assertDatabaseCount('users', 0));
});

test('database.migrator 自定义迁移器（callable）覆盖 phinx', function () {
    $called = false;
    webman_mock_use_app('webman-db-app', [
        'testing' => ['database' => ['migrator' => function () use (&$called): void {
            $called = true;
        }]],
        'database' => require database_setup_fixture_dir() . '/config/database.php',
    ]);

    $case = new DatabaseSetupTestCase('capture');
    $case->setUpDatabase();

    expect($called)->toBeTrue()
        // 自定义迁移器未建表，连接仍自动注入
        ->and($case->databaseConnection())->toBeInstanceOf(PDO::class);
});

test('手动 setDatabaseConnection 后 setUpDatabase 不覆盖连接', function () {
    $case = new DatabaseSetupTestCase('capture');
    // 手动注入同一文件库的连接（迁移经 phinx 独立连接执行，truncate 用注入的连接）
    $pdo = new PDO('sqlite:' . database_setup_fixture_dir() . '/runtime/db.sqlite');
    $case->setDatabaseConnection($pdo);

    $case->setUpDatabase();

    expect($case->databaseConnection())->toBe($pdo)
        ->and($case->assertDatabaseCount('users', 0));
});

test('setUpDatabase expect 校验拦截非期望驱动', function () {
    $case = new DatabaseSetupTestCase('capture');

    expect(fn() => $case->setUpDatabase(['mysql' => 'x']))
        ->toThrow(RuntimeException::class, '测试数据库驱动期望 mysql');
});

test('setUpDatabase expect 校验拦截非期望库文件', function () {
    $case = new DatabaseSetupTestCase('capture');

    expect(fn() => $case->setUpDatabase(['sqlite' => 'prod.sqlite']))
        ->toThrow(RuntimeException::class, '防误连业务库');
});

test('PhinxMigrator 直接执行迁移且幂等', function () {
    $migrator = new PhinxMigrator(database_setup_fixture_dir() . '/phinx.php');
    $migrator->migrate();
    $migrator->migrate(); // 幂等：phinxlog 已记录，不重复执行

    $pdo = new PDO('sqlite:' . database_setup_fixture_dir() . '/runtime/db.sqlite');
    $stmt = $pdo->query('SELECT COUNT(*) FROM `phinxlog`');
    expect($stmt === false ? -1 : (int)$stmt->fetchColumn())->toBe(1);
});

test('PhinxMigrator 配置文件不存在时抛可读异常', function () {
    $migrator = new PhinxMigrator(database_setup_fixture_dir() . '/not-exists.php');

    expect(fn() => $migrator->migrate())
        ->toThrow(RuntimeException::class, 'phinx 配置文件不存在');
});

test('setUpDatabase transaction 模式：插入后 rollBackDatabase 数据消失', function () {
    $case = new DatabaseSetupTestCase('capture');
    $case->setUpDatabase(['sqlite' => 'db.sqlite'], 'transaction');
    $case->databaseConnection()->exec("INSERT INTO `users` (`name`) VALUES ('tx')");
    expect($case->assertDatabaseCount('users', 1));

    $case->rollBackDatabase();

    expect($case->assertDatabaseCount('users', 0))
        // 回滚幂等：无事务时再次调用不抛异常
        ->and($case->rollBackDatabase())->toBeInstanceOf(TestCase::class);
});

test('setUpDatabase transaction 模式：tearDown 自动回滚', function () {
    $case = new DatabaseSetupTestCase('capture');
    $case->setUpDatabase(['sqlite' => 'db.sqlite'], 'transaction');
    $case->databaseConnection()->exec("INSERT INTO `users` (`name`) VALUES ('tx')");
    expect($case->assertDatabaseCount('users', 1));

    $case->runTearDown();

    expect($case->assertDatabaseCount('users', 0));
});

test('setUpDatabase memory 模式：迁移到内存库且文件库无迁移痕迹', function () {
    $case = new DatabaseSetupTestCase('capture');
    $case->setUpDatabase(['sqlite' => 'db.sqlite'], 'memory');

    expect($case->databaseConnection())->toBeInstanceOf(PDO::class)
        ->and($case->assertDatabaseCount('users', 0));
    // 文件库未被迁移污染（phinx.php 加载时 new PDO 会创建空文件，但不应有 phinxlog）
    $filePdo = new PDO('sqlite:' . database_setup_fixture_dir() . '/runtime/db.sqlite');
    $stmt = $filePdo->query("SELECT COUNT(*) FROM `sqlite_master` WHERE `type` = 'table' AND `name` = 'phinxlog'");
    expect($stmt === false ? -1 : (int)$stmt->fetchColumn())->toBe(0);
});

test('setUpDatabase memory 模式：每测试全新内存库', function () {
    $first = new DatabaseSetupTestCase('capture');
    $first->setUpDatabase(['sqlite' => 'db.sqlite'], 'memory');
    $first->databaseConnection()->exec("INSERT INTO `users` (`name`) VALUES ('mem')");
    expect($first->assertDatabaseCount('users', 1));

    // 新实例 = 新 :memory: 库（重新迁移，数据为空）
    $second = new DatabaseSetupTestCase('capture');
    $second->setUpDatabase(['sqlite' => 'db.sqlite'], 'memory');
    expect($second->assertDatabaseCount('users', 0));
});
