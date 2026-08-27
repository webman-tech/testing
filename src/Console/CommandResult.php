<?php

declare(strict_types=1);

namespace WebmanTech\Testing\Console;

use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

/**
 * webman CLI 命令（php webman <args>）的执行结果
 */
final class CommandResult
{
    public function __construct(
        private readonly Process $process,
    ) {
    }

    public function exitCode(): ?int
    {
        return $this->process->getExitCode();
    }

    public function stdout(): string
    {
        return $this->process->getOutput();
    }

    public function stderr(): string
    {
        return $this->process->getErrorOutput();
    }

    /**
     * stdout + stderr 合并输出（webman/console 的部分信息走 stderr）
     */
    public function output(): string
    {
        return $this->stdout() . $this->stderr();
    }

    public function isSuccessful(): bool
    {
        return $this->exitCode() === 0;
    }

    public function assertSuccessful(): self
    {
        Assert::assertSame(0, $this->exitCode(), "命令执行失败:\n" . $this->output());

        return $this;
    }

    /**
     * assertSuccessful 的别名（laravel PendingCommand::assertOk 对应物）
     */
    public function assertOk(): self
    {
        return $this->assertSuccessful();
    }

    /**
     * 断言命令执行失败（exitCode != 0）
     */
    public function assertFailed(): self
    {
        Assert::assertNotSame(0, $this->exitCode(), "期望命令执行失败，实际成功:\n" . $this->output());

        return $this;
    }

    public function assertExitCode(int $code): self
    {
        Assert::assertSame($code, $this->exitCode(), "期望 exitCode={$code}，实际 " . var_export($this->exitCode(), true) . ":\n" . $this->output());

        return $this;
    }

    public function assertNotExitCode(int $code): self
    {
        Assert::assertNotSame($code, $this->exitCode(), "期望 exitCode!={$code}，实际一致:\n" . $this->output());

        return $this;
    }

    public function assertOutputContains(string $needle): self
    {
        Assert::assertStringContainsString($needle, $this->output());

        return $this;
    }

    /**
     * 断言 stdout 存在与给定内容完全一致的整行（laravel PendingCommand::expectsOutput 对应物）
     */
    public function expectsOutput(string $output): self
    {
        Assert::assertContains(
            $output,
            $this->stdoutLines(),
            "stdout 中不存在整行 [{$output}]:\n" . $this->stdout(),
        );

        return $this;
    }

    /**
     * 断言 stdout 不存在与给定内容完全一致的整行
     */
    public function doesntExpectOutput(string $output): self
    {
        Assert::assertNotContains(
            $output,
            $this->stdoutLines(),
            "stdout 中不应存在整行 [{$output}]:\n" . $this->stdout(),
        );

        return $this;
    }

    /**
     * 断言输出包含给定片段（stdout + stderr；laravel PendingCommand::expectsOutputToContain 对应物）
     */
    public function expectsOutputToContain(string $output): self
    {
        return $this->assertOutputContains($output);
    }

    private function stdoutLines(): array
    {
        $lines = array_map('trim', explode("\n", $this->stdout()));

        return array_values(array_filter($lines, fn(string $line): bool => $line !== ''));
    }
}
