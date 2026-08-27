<?php

declare(strict_types=1);

/*
 * 组件自身单测的 Pest 配置与公共 helper。
 *
 * 单测不启动真实 webman 进程（那是 e2e/集成测试的职责，见 AGENTS.md），
 * 这里只验证组件在进程内的行为：TestResponse 断言语义、配置传导、请求组装、数据库断言等。
 */

if (!function_exists('fixture_get_path')) {
    /**
     * 获取 tests/Fixtures 下的 fixture 绝对路径
     */
    function fixture_get_path(string $path): string
    {
        return __DIR__ . '/Fixtures/' . ltrim($path, '/');
    }
}
