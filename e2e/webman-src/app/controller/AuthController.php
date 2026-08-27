<?php

namespace app\controller;

use support\Request;
use support\Response;

/**
 * 认证演示（e2e 简化实现：固定 token，不依赖第三方认证包）
 */
class AuthController
{
    public const TOKEN = 'e2e-secret-token';

    public function login(Request $request): Response
    {
        return json([
            'access_token' => self::TOKEN,
            'token_type' => 'Bearer',
        ]);
    }

    public function user(Request $request): Response
    {
        return json([
            'id' => 'e2e-user-1',
            'name' => 'e2e-user',
        ]);
    }
}
