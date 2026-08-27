<?php

declare(strict_types=1);

/*
 * e2e Pest 配置与公共 helper。
 *
 * tests 目录按 laravel 骨架规范划分：
 * - tests/Unit/：纯单元测试（不启动 server，不绑定基类）
 * - tests/Feature/：集成测试（真实 webman 进程 + HTTP），绑定应用级基类 Tests\TestCase
 *
 * 测试闭包经 pest()->extend(Tests\TestCase::class)->in('Feature') 绑定（laravel 骨架同款机制），
 * 请求语法为 laravel 风格：$this->getJson() / $this->postJson() / $this->withToken() ...
 *
 * server 进程编排统一由 webman-tech/testing 组件提供；这里仅保留 e2e 特有的
 * 断言辅助（crontab 副作用文件行数统计）。
 */

use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

if (!function_exists('e2e_crontab_count')) {
    /**
     * 统计 crontab 副作用文件的行数（每执行一次追加一行）；$runtimePath 为 server 的 runtime 目录
     */
    function e2e_crontab_count(string $runtimePath): int
    {
        $file = rtrim($runtimePath, '/') . '/e2e-crontab.log';

        return is_file($file) ? count(file($file, FILE_IGNORE_NEW_LINES)) : 0;
    }
}
