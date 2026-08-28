<?php

declare(strict_types=1);

use WebmanTech\Testing\TestCase;

// 绑定组件测试基类（pest extend 机制）：测试方法经 $this-> 直接使用组件全部能力
// （get/post/webmanCommand/assertDatabaseHas/webmanWaitFor 等，详见组件 README）
pest()->extend(TestCase::class)->in(__DIR__);
