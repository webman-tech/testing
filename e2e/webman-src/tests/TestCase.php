<?php

namespace Tests;

use WebmanTech\Testing\TestCase as BaseTestCase;

/**
 * 应用级测试基类（laravel 骨架 tests/TestCase.php 的对应物）
 *
 * 需要自定义 setUp / 公共 helper 时在此扩展；
 * 测试文件无需直接继承——pest 经 tests/Pest.php 的
 * pest()->extend(TestCase::class)->in('Feature') 绑定后闭包内直接 $this->xxx。
 */
abstract class TestCase extends BaseTestCase
{
    //
}
