<?php

use PHPUnit\Framework\AssertionFailedError;
use WebmanTech\Testing\TestCase;

/*
 * InteractsWithDatabase 的数据库断言（sqlite :memory: 验证断言语义；
 * 真实跨进程共享库的场景由 e2e 覆盖）
 */

/**
 * 带 sqlite 连接与 users 表的最小测试实例
 */
class DatabaseAssertTestCase extends TestCase
{
    public function __construct(string $name)
    {
        parent::__construct($name);

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, age INTEGER, deleted_at TEXT NULL)');
        $pdo->exec("INSERT INTO users (name, age) VALUES ('webman', 3)");
        $pdo->exec("INSERT INTO users (name, age) VALUES ('laravel', 11)");
        $this->setDatabaseConnection($pdo);
    }
}

function database_case_new(): DatabaseAssertTestCase
{
    return new DatabaseAssertTestCase('capture');
}

test('assertDatabaseHas/assertDatabaseMissing 按条件断言存在性', function () {
    $case = database_case_new();

    expect($case->assertDatabaseHas('users', ['name' => 'webman']))->toBeInstanceOf(TestCase::class)
        ->and($case->assertDatabaseHas('users', ['name' => 'webman', 'age' => 3]))->toBeInstanceOf(TestCase::class)
        ->and($case->assertDatabaseMissing('users', ['name' => 'not-exists']))->toBeInstanceOf(TestCase::class)
        ->and(fn() => $case->assertDatabaseHas('users', ['name' => 'not-exists']))->toThrow(AssertionFailedError::class)
        ->and(fn() => $case->assertDatabaseMissing('users', ['name' => 'webman']))->toThrow(AssertionFailedError::class);
});

test('assertDatabaseCount/assertDatabaseEmpty 断言数量', function () {
    $case = database_case_new();

    expect($case->assertDatabaseCount('users', 2))->toBeInstanceOf(TestCase::class)
        ->and(fn() => $case->assertDatabaseCount('users', 3))->toThrow(AssertionFailedError::class)
        // 表非空时 assertDatabaseEmpty 应失败
        ->and(fn() => $case->assertDatabaseEmpty('users'))->toThrow(AssertionFailedError::class);
});

test('assertSoftDeleted/assertNotSoftDeleted 按 deleted_at 断言', function () {
    $case = database_case_new();
    $pdo = $case->databaseConnection();
    $pdo->exec("UPDATE users SET deleted_at = '2026-01-01 00:00:00' WHERE name = 'laravel'");

    expect($case->assertSoftDeleted('users', ['name' => 'laravel']))->toBeInstanceOf(TestCase::class)
        ->and($case->assertNotSoftDeleted('users', ['name' => 'webman']))->toBeInstanceOf(TestCase::class)
        ->and(fn() => $case->assertSoftDeleted('users', ['name' => 'webman']))->toThrow(AssertionFailedError::class)
        ->and(fn() => $case->assertNotSoftDeleted('users', ['name' => 'laravel']))->toThrow(AssertionFailedError::class);
});

test('非法表名/列名拒绝执行（防 SQL 注入）', function () {
    $case = database_case_new();

    expect(fn() => $case->assertDatabaseHas('users; DROP TABLE users', ['name' => 'x']))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $case->assertDatabaseHas('users', ['name; DROP TABLE users' => 'x']))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $case->assertDatabaseCount('users; DROP', 1))->toThrow(InvalidArgumentException::class)
        // 注入被拦截后表仍完好
        ->and($case->assertDatabaseCount('users', 2))->toBeInstanceOf(TestCase::class);
});

test('未设置连接时抛可读异常', function () {
    $case = new TestCase('capture');

    expect(fn() => $case->assertDatabaseHas('users', ['name' => 'x']))
        ->toThrow(RuntimeException::class, '未设置数据库连接');
});
