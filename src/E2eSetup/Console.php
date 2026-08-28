<?php

declare(strict_types=1);

namespace WebmanTech\Testing\E2eSetup;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use WebmanTech\Testing\E2eSetup\Command\InitCommand;
use WebmanTech\Testing\E2eSetup\Command\InstallCommand;
use WebmanTech\Testing\E2eSetup\Command\SyncCommand;

/**
 * e2e-setup 命令入口（bin/e2e-setup 薄壳调用），基于 symfony/console（框架无关标准 CLI 库）。
 * 本工具聚焦 e2e 应用安装编排（setup 能力），不预留其他子域。
 *
 * 用法：
 *   vendor/bin/e2e-setup install [app] [--vcs] [--config 定义文件]   完整安装（create-project → patch → update → reinstall → sync）
 *   vendor/bin/e2e-setup sync [app] [--config 定义文件]              仅同步自有代码（dev 快速迭代）
 *   vendor/bin/e2e-setup init [--framework=webman|laravel] [--dir=目录]  生成 e2e 脚手架（默认 webman）
 *
 * 定义文件默认 {cwd}/e2e/e2e-setup.php，返回 SetupConfig 实例（见 Definition\Definition）。
 * 参数解析、--help/--version、异常渲染均由 symfony/console 承担，这里只做命令装配。
 */
final class Console
{
    public const NAME = 'e2e-setup';

    /**
     * @param list<string> $argv 含命令名（$argv[0]）
     * @param (callable(array<int, string>, ?string, array<string, string>): void)|null $runner 透传 Installer（测试注入用）
     */
    public static function run(array $argv, ?callable $runner = null): int
    {
        $application = new Application(self::NAME);
        $application->setAutoExit(false);
        // addCommands 批量注册（Application::add() 自 7.4 弃用，addCommand() 仅 7.4+ 有，批量注册双版本兼容）
        $application->addCommands([
            new InstallCommand($runner),
            new SyncCommand($runner),
            new InitCommand(),
        ]);

        // ArgvInput 按真实命令行语义解析（跳过 $argv[0]），与 bin 入口行为一致
        return $application->run(new ArgvInput($argv));
    }
}
