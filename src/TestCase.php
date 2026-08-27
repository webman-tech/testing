<?php

declare(strict_types=1);

namespace WebmanTech\Testing;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * 测试基类（laravel TestCase 的真实进程对应物）
 *
 * pest 用户在 tests/Pest.php 中 `pest()->extend(TestCase::class)->in(...)`
 * 绑定后，测试闭包内即可 `$this->postJson(...)`（laravel 同款语法）。
 *
 * 能力按 Concerns 组合组织（对齐 laravel TestCase 的结构）：
 * - MakesHttpRequests：HTTP 请求
 * - InteractsWithAuthentication：认证交互
 * - InteractsWithConsole：CLI 命令（对应 laravel artisan()）
 * - InteractsWithServer：server 编排/runtime 定位/副作用轮询（webman 特有）
 * - InteractsWithDatabase：数据库断言
 */
class TestCase extends BaseTestCase
{
    use Concerns\MakesHttpRequests;
    use Concerns\InteractsWithAuthentication;
    use Concerns\InteractsWithConsole;
    use Concerns\InteractsWithDatabase;
    use Concerns\InteractsWithServer;
}
