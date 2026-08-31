<?php

// 跨进程数据库断言：server 进程内经 HTTP/CLI 写库，测试进程 setUpDatabase 后
// PDO 直连同一 sqlite 文件库断言（:memory: 无法跨进程，e2e 用 runtime/e2e.sqlite）

beforeEach(function () {
    $this->setUpDatabase(['sqlite' => 'e2e.sqlite']);
});

test('HTTP 写入的数据可被测试进程直连断言', function () {
    $this->postJson('/data/users', ['email' => 'db@example.com', 'name' => 'db-user'])->assertCreated();

    $this->assertDatabaseHas('users', ['email' => 'db@example.com', 'name' => 'db-user'])
        ->assertDatabaseCount('users', 1);
});

test('CLI 命令写入的数据可被测试进程直连断言', function () {
    $this->webmanCommand('e2e:seed')->assertOk();

    $this->assertDatabaseHas('users', ['email' => 'seed@example.com'])
        ->assertDatabaseCount('users', 1);
});

test('软删除后 assertSoftDeleted 成立，列表查询不可见', function () {
    $id = $this->postJson('/data/users', ['email' => 'soft@example.com', 'name' => 'soft-user'])->json('id');
    $this->delete('/data/users/' . $id)->assertOk();

    $this->assertSoftDeleted('users', ['id' => $id]);
    $this->getJson('/data/users')->assertJsonPath('count', 0);
});
