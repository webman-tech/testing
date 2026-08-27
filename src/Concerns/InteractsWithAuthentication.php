<?php

declare(strict_types=1);

namespace WebmanTech\Testing\Concerns;

/**
 * 认证交互（laravel InteractsWithAuthentication 的对应物）
 *
 * 真实进程模式下无法操作 server 进程内的 guard（laravel 的 actingAs 语义），
 * 认证态统一通过 token 由被测应用的认证中间件建立。
 * 注意：依赖 MakesHttpRequests::withToken()，宿主类需同时组合该 trait。
 */
trait InteractsWithAuthentication
{
    /**
     * withToken 的语义别名（laravel actingAs 的真实进程替代物：
     * 认证态通过 token 由被测应用的认证中间件建立，而非容器绑定）
     */
    public function actingViaToken(string $token, string $type = 'Bearer'): static
    {
        return $this->withToken($token, $type);
    }
}
