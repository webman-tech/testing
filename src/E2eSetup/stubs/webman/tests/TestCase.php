<?php

declare(strict_types=1);

namespace Tests;

use WebmanTech\Testing\TestCase as BaseTestCase;

/**
 * 应用级测试基类：继承组件 TestCase 即可使用全部能力
 * （真实 webman 进程编排、HTTP 断言、CLI 命令、数据库断言、副作用等待）
 */
abstract class TestCase extends BaseTestCase
{
}
