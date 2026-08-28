<?php

declare(strict_types=1);

use WebmanTech\Testing\E2eSetup\Definition\AppConfig;
use WebmanTech\Testing\E2eSetup\SetupConfig;

/**
 * e2e 应用定义模板（框架无关编排，能力本体见 testing 组件 src/E2eSetup 与根 README「为插件包搭建 e2e 测试」）
 *
 * rector.php 风格的类写法：SetupConfig::configure()->app(应用名, AppConfig::configure()->...)
 * 相对路径基于本文件所在目录（e2e/）解析；package() 未传 path 时默认指向项目根（被测包）。
 * 生成物 target_dir 应加入 .gitignore；src_dir 为自有代码（config/tests 等），install 时覆盖式同步。
 *
 * 用法：
 *   vendor/bin/e2e-setup install [app]   完整安装（create-project → patch → update → reinstall → sync）
 *   vendor/bin/e2e-setup sync [app]      仅同步自有代码（dev 快速迭代）
 *   vendor/bin/e2e-setup install --vcs   被测包经 GitHub VCS dev-main 安装（需先推送 main）
 */

return SetupConfig::configure()
    ->app('webman', AppConfig::configure()
        ->skeleton('workerman/webman')
        // 钉骨架版本（可选）：->skeleton('workerman/webman', '^2.0')，create-project 时传入（laravel 场景常用 ^12.0 钉主版本）
        ->targetDir('webman')
        ->srcDir('app-src')
        // 被测包：不传 path 默认项目根（path repository + symlink，改动即时生效）
        ->package('vendor/your-package')
        // 显式 path（支持目录或 glob，多包同 path 自动合并为单条 repository）：
        // ->package('vendor/package-b', '../packages')
        // 显式 vcs 安装：
        // ->package('vendor/package-c', null, 'https://github.com/vendor/package-c.git')
        ->require([
            // 被测包的业务依赖（如 webman 插件）
        ])
        ->requireDev([
            'pestphp/pest' => '^3.8',              // 测试框架（也可用 phpunit，与组件断言双兼容）
            'guzzlehttp/guzzle' => '^7.8',         // 组件 PSR-18 HTTP 客户端（自动发现）
        ])
        // 批量 composer update 时包内 Install.php 不触发；webman 插件场景需显式声明
        // 待 reinstall 的包（如 'webman/console'）以落地 CLI 入口与 config/plugin/<package>/ 模板
        ->reinstallPackages([])
    );
// laravel 场景示例（init --framework=laravel 的 app-src 同款；测试执行用骨架自带 PHPUnit，
// 用 Pest 风格需补 pestphp/pest-plugin-laravel；被测包 ServiceProvider 注册在 bootstrap/providers.php）
// ->app('laravel', AppConfig::configure()
//     ->skeleton('laravel/laravel', '^12.0')
//     ->targetDir('laravel')
//     ->srcDir('app-src')
//     ->package('vendor/your-laravel-package')
//     ->require([])
//     ->requireDev([
//         'pestphp/pest' => '^3.8',
//         'pestphp/pest-plugin-laravel' => '^3.0',
//     ])
//     ->reinstallPackages([])
// )
;
