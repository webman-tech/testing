<?php

declare(strict_types=1);

// PHPStan 分析前真实执行的文件：为静态分析注册被测应用（webman 项目）才有的类。
// 真实环境中由被测应用 vendor/workerman/webman-framework 提供（测试进程加载其
// vendor/autoload.php 后自动就绪）；组件不直接依赖 webman，此文件仅用于静态分析。
namespace Webman {

    /**
     * 仅静态分析用的声明（真实实现见 webman-framework 的 src/Config.php）
     */
    class Config
    {
        /**
         * 合并加载配置目录（加载过程会 require 各配置文件，进程内 config() 数据随之可用）
         *
         * @param string  $configPath  配置目录
         * @param array   $excludeFile 排除的文件名（不含 .php 后缀）
         * @param string|null $key     仅加载该键对应的配置
         */
        public static function load(string $configPath, array $excludeFile = [], ?string $key = null): void
        {
        }
    }
}
