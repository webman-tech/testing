<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * laravel 应用级测试基类（骨架自带形态）：真实 HTTP/功能测试用骨架自带的 Laravel 测试能力。
 * 被测包（laravel 插件）的 ServiceProvider 注册：laravel 11+ 在 bootstrap/providers.php（骨架已含）。
 */
abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
}
