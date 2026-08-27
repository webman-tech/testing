<?php

declare(strict_types=1);

// PHPStan 分析前真实执行的文件：为静态分析注册被测应用（webman 项目）才有的函数。
// 真实环境中由 webman-framework 的 composer.json autoload.files 注册的 helpers.php 提供（测试进程
// 加载被测应用 vendor/autoload.php 时自动就绪）；组件不直接依赖 webman，此文件仅用于静态分析。
if (!function_exists('config')) {
    /**
     * webman 框架的配置读取函数（被测应用 vendor/workerman/webman-framework 的 helpers.php 提供）
     *
     * @param string|null $key     配置键（点分路径；null 返回全部配置）
     * @param mixed       $default 键不存在时的默认值
     *
     * @return mixed
     */
    function config(?string $key = null, mixed $default = null): mixed
    {
        return $default;
    }
}
