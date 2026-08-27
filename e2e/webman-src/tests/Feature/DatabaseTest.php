<?php

// 跨进程数据库断言：server 进程内经 HTTP/CLI 写库，测试进程 PDO 直连同一 sqlite 文件库断言
// （:memory: 仅存在于 server 进程内测试进程连不上，e2e 用文件库 runtime/e2e.sqlite，
// 经 webmanRuntimePath('e2e.sqlite') 与 server 侧 base_path()/runtime 定位同源文件）

beforeEach(function () {
    $this->setDatabaseConnection(new PDO('sqlite:' . $this->webmanRuntimePath('e2e.sqlite')));
    // 数据重置推荐应用侧 reset 端点模式（跨进程无容器魔法）
    $this->post('/data/reset')->assertOk();
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
