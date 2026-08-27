<?php

declare(strict_types=1);

namespace WebmanTech\Testing\Concerns;

use Symfony\Component\Process\Process;
use WebmanTech\Testing\Console\CommandResult;

/**
 * webman CLI 命令交互（laravel InteractsWithConsole::artisan() 的对应物）
 *
 * 经 `webmanCommand()` 在应用的 appDir 下执行 `php <command> <args>`
 * （命令名取 TestingConfig::command，默认 `webman`）。
 */
trait InteractsWithConsole
{
    /**
     * 执行 webman CLI 命令（php <command> <args>，默认入口为 webman/console 落地的 `webman` 文件）
     *
     * 仅面向业务命令；server 的 start/stop 由 Server 生命周期管理，勿用本方法控制 server。
     * 不启动 HTTP server（只复用 TestingConfig 的 appDir/phpBinary/command 等定位信息）。
     */
    public function webmanCommand(string ...$args): CommandResult
    {
        $config = $this->webmanServer()->config();
        $process = new Process(
            array_merge([$config->phpBinary, $config->command], $args),
            $config->appDir,
            null,
            null,
            $config->processTimeout,
        );
        $process->run();

        return new CommandResult($process);
    }
}
