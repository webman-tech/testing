<?php

// 认证演示：login 签发 token、withToken 建立登录态、无效 token 401

test('登录可签发 token', function () {
    $this->postJson('/auth/login')
        ->assertOk()
        ->assertJsonStructure(['access_token', 'token_type']);
});

test('携带有效 token 可获取当前用户', function () {
    $token = $this->postJson('/auth/login')->json('access_token');

    $this->withToken($token)
        ->getJson('/auth/user')
        ->assertOk()
        ->assertJson(['id' => 'e2e-user-1', 'name' => 'e2e-user']);
});

test('无 token 访问受保护接口返回 401', function () {
    $this->getJson('/auth/user')->assertUnauthorized();
});

test('无效 token 返回 401', function () {
    $this->withToken('invalid-token')->getJson('/auth/user')->assertUnauthorized();
});
