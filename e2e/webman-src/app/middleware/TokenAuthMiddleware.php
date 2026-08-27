<?php

namespace app\middleware;

use app\controller\AuthController;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * Bearer token 中间件（e2e 简化实现：校验固定 token，对应组件 withToken/actingViaToken 的登录态建立方式）
 */
class TokenAuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $authorization = (string)$request->header('authorization', '');
        $token = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '';

        if ($token !== AuthController::TOKEN) {
            return json(['error' => 'unauthorized'])->withStatus(401);
        }

        return $handler($request);
    }
}
