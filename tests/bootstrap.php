<?php

declare(strict_types=1);

/*
 * 单测进程的 PHPUnit bootstrap。
 *
 * 组件 require-dev 引入 webman-framework 后 config() 为真实实现（helpers.php 经
 * autoload.files 先于测试代码注册），单测配置 mock 见 tests/Pest.php 的
 * webman_mock_use_app() / webman_mock_config_apply()。这里只需加载 autoload。
 */

require_once __DIR__ . '/../vendor/autoload.php';
