<?php

declare(strict_types=1);

use WebmanTech\Testing\E2eSetup\Definition\AppConfig;
use WebmanTech\Testing\E2eSetup\SetupConfig;

/**
 * 本仓库 e2e 应用定义（框架无关编排的 webman 样例；能力本体见 src/E2eSetup，用法见根 README「为插件包搭建 e2e 测试」）
 *
 * rector.php 风格的类写法：SetupConfig::configure()->app(应用名, AppConfig::configure()->...)
 * 用法（composer scripts 已封装）：
 *   composer e2e:install   完整安装（create-project → patch → update → reinstall → sync）
 *   composer e2e:sync      仅同步自有代码（dev 快速迭代）
 *   composer e2e:vcs       testing 组件经 GitHub VCS dev-main 安装（默认 path 引用当前仓库代码）
 *
 * 生成物 e2e/webman 被 git 忽略，删除后重新 install 即基于最新官方骨架。
 * 相对路径基于本文件所在目录（e2e/）解析；package() 未传 path 时默认指向本仓库根（被测包）。
 */

return SetupConfig::configure()
    ->app('webman', AppConfig::configure()
        ->skeleton('workerman/webman')
        ->targetDir('webman')
        ->srcDir('webman-src')
        // testing 组件（被测对象）：不传 path 默认指向本仓库根；--vcs 时切换为 GitHub VCS dev-main
        ->package('webman-tech/testing')
        // crontab 副作用演示（webman-src/app/process/Crontab.php）
        ->require([
            'workerman/crontab' => '^1.0',
            // 数据库（webman-src/config/database.php 经 env 覆盖切换 sqlite，与端口 APP_PORT 同一模式）
            'webman/database' => '^2.1',
        ])
        ->requireDev([
            'pestphp/pest' => '^3.8',
            // 数据库迁移（setUpDatabase 的 PhinxMigrator 需要；随包 suggest）
            'robmorgan/phinx' => '^0.16',
            // webman CLI 入口（webmanCommand() 依赖 `webman` 可执行文件）
            'webman/console' => '^2.0',
            // 被测组件本身（path repository 已声明，此处置 dev-main 与 versions 匹配）
            'webman-tech/testing' => 'dev-main',
            // 组件的 PSR-18 HTTP 客户端（自动发现；组件不强制依赖 guzzle）
            'guzzlehttp/guzzle' => '^7.8',
        ])
        // 批量 composer update 时包内 Install.php 不触发，需 reinstall 落地 webman CLI 入口与 config/database.php 模板
        ->reinstallPackages([
            'webman/console',
            'webman/database',
        ])
    );
