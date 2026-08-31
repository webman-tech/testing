<?php

declare(strict_types=1);

use WebmanTech\Testing\TestCase;

/*
 * 数据库隔离模式的「被测应用 Db 同源」路径（真实 support\Db，webman/database）
 *
 * 组件 require-dev 已引入真实 webman/database，单测直接走被测应用同款 Db 门面：
 * 覆盖 setUpDatabase 的 isolation=transaction / memory 在 support\Db 场景下的行为——
 * 断言连接与 Eloquent（经 support\Db 单例）同源，事务回滚 / 内存库隔离才真正覆盖应用写入。
 */

class DatabaseIsolationTestCase extends TestCase
{
    public function runTearDown(): void
    {
        $this->tearDown();
    }
}

beforeEach(function () {
    webman_mock_use_app('webman-db-app');
    // 重置进程级迁移标记 + 重建 fixture 库文件 + 重置真实 support\Db 全局状态
    database_setup_reset();
    database_support_db_reset();
});

test('transaction 模式优先使用被测应用 Db 的同源连接', function () {
    $case = new DatabaseIsolationTestCase('capture');
    $case->setUpDatabase(['sqlite' => 'db.sqlite'], 'transaction');

    // 断言连接 = Eloquent（support\Db）同源连接，事务才能覆盖应用写入
    expect($case->databaseConnection())->toBe(\support\Db::connection('sqlite')->getPdo())
        ->and($case->databaseConnection()->inTransaction())->toBeTrue();
});

test('transaction 模式：经同源连接插入后 rollBackDatabase 数据消失', function () {
    $case = new DatabaseIsolationTestCase('capture');
    $case->setUpDatabase(['sqlite' => 'db.sqlite'], 'transaction');
    $case->databaseConnection()->exec("INSERT INTO `users` (`name`) VALUES ('tx')");
    expect($case->assertDatabaseCount('users', 1));

    $case->rollBackDatabase();

    expect($case->assertDatabaseCount('users', 0));
});

test('memory 模式把 Eloquent 连接切换到 :memory:（同源）', function () {
    $case = new DatabaseIsolationTestCase('capture');
    $case->setUpDatabase(['sqlite' => 'db.sqlite'], 'memory');

    // setPdo 生效：support\Db 同源连接即注入的 :memory: PDO，且迁移落在内存库上
    expect($case->databaseConnection())->toBe(\support\Db::connection('sqlite')->getPdo())
        ->and($case->assertDatabaseCount('users', 0));
});

test('memory 模式：每测试全新内存库（第二个实例数据不存在）', function () {
    $first = new DatabaseIsolationTestCase('capture');
    $first->setUpDatabase(['sqlite' => 'db.sqlite'], 'memory');
    $first->databaseConnection()->exec("INSERT INTO `users` (`name`) VALUES ('mem')");
    expect($first->assertDatabaseCount('users', 1));

    // 新实例 = 新 :memory: 库（迁移重新执行，数据为空）
    $second = new DatabaseIsolationTestCase('capture');
    $second->setUpDatabase(['sqlite' => 'db.sqlite'], 'memory');
    expect($second->assertDatabaseCount('users', 0));
});

test('memory 模式后 restoreDatabaseConnection 恢复 Db 连接到文件库', function () {
    $case = new DatabaseIsolationTestCase('capture');
    $case->setUpDatabase(['sqlite' => 'db.sqlite'], 'memory');
    $memPdo = $case->databaseConnection();
    expect(\support\Db::connection('sqlite')->getPdo())->toBe($memPdo);

    $case->restoreDatabaseConnection();

    // 连接恢复为应用配置的文件库（非 :memory:），且幂等可重复调用
    $restored = \support\Db::connection('sqlite')->getPdo();
    expect($restored)->not->toBe($memPdo)
        ->and($restored->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC)['file'])
        ->toContain('runtime/db.sqlite')
        ->and($case->restoreDatabaseConnection())->toBeInstanceOf(TestCase::class);
});

test('memory 模式后 tearDown 自动恢复 Db 连接到文件库', function () {
    $case = new DatabaseIsolationTestCase('capture');
    $case->setUpDatabase(['sqlite' => 'db.sqlite'], 'memory');
    expect(\support\Db::connection('sqlite')->getPdo())->toBe($case->databaseConnection());

    $case->runTearDown();

    // 组件 TestCase::tearDown 自动恢复，同进程后续用例不会静默连到已销毁的内存库
    $restored = \support\Db::connection('sqlite')->getPdo();
    expect($restored->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC)['file'])
        ->toContain('runtime/db.sqlite');
});
