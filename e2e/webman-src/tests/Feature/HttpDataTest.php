<?php

// HTTP 请求与 TestResponse 断言（JSON/header/状态码/参数校验）

test('POST JSON 创建用户并断言 201 与结构', function () {
    $this->postJson('/data/users', ['email' => 'a@example.com', 'name' => 'Alice'])
        ->assertCreated()
        ->assertJsonStructure(['id']);
});

test('参数缺失返回 422 且可断言错误信息', function () {
    $this->postJson('/data/users', [])
        ->assertStatus(422)
        ->assertJsonPath('error', 'email/name required');
});

test('GET 列表可断言 count 与逐条数据（先 reset 保证干净）', function () {
    $this->post('/data/reset')->assertOk();
    $this->postJson('/data/users', ['email' => 'b@example.com', 'name' => 'Bob'])->assertCreated();

    $this->getJson('/data/users')
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('users.0.email', 'b@example.com');
});

test('DELETE 软删除不存在用户返回 404', function () {
    $this->delete('/data/users/99999')->assertNotFound();
});
