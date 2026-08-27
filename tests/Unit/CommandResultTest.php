<?php

use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Process\Process;
use WebmanTech\Testing\Console\CommandResult;

/*
 * CommandResult 的结果与断言（真实起 php 小进程；webman 命令路径由 e2e 覆盖）
 */

function testing_command(array $command): CommandResult
{
    $process = new Process($command, null, ['XDEBUG_MODE' => 'off']);
    $process->run();

    return new CommandResult($process);
}

test('成功命令的结果与断言', function () {
    $result = testing_command([PHP_BINARY, '-r', 'echo "hello";']);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->exitCode())->toBe(0)
        ->and($result->stdout())->toBe('hello')
        ->and($result->stderr())->toBe('')
        ->and($result->output())->toBe('hello')
        // 断言链式返回自身
        ->and($result->assertSuccessful()->assertExitCode(0)->assertOutputContains('ell'))->toBeInstanceOf(CommandResult::class);
});

test('失败命令的结果与断言', function () {
    $result = testing_command([PHP_BINARY, '-r', 'fwrite(STDERR, "err-msg"); exit(3);']);

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->exitCode())->toBe(3)
        ->and($result->stdout())->toBe('')
        ->and($result->stderr())->toBe('err-msg')
        // output 合并 stdout + stderr
        ->and($result->output())->toBe('err-msg')
        ->and($result->assertExitCode(3)->assertOutputContains('err-msg'))->toBeInstanceOf(CommandResult::class)
        ->and(fn() => $result->assertSuccessful())->toThrow(AssertionFailedError::class)
        ->and(fn() => $result->assertExitCode(0))->toThrow(AssertionFailedError::class)
        ->and(fn() => $result->assertOutputContains('missing'))->toThrow(AssertionFailedError::class);
});

test('assertOk/assertFailed/assertNotExitCode 语义', function () {
    $ok = testing_command([PHP_BINARY, '-r', 'echo "ok";']);
    $fail = testing_command([PHP_BINARY, '-r', 'exit(3);']);

    expect($ok->assertOk())->toBeInstanceOf(CommandResult::class)
        ->and($fail->assertFailed())->toBeInstanceOf(CommandResult::class)
        ->and($fail->assertNotExitCode(0))->toBeInstanceOf(CommandResult::class)
        ->and(fn() => $fail->assertOk())->toThrow(AssertionFailedError::class)
        ->and(fn() => $ok->assertFailed())->toThrow(AssertionFailedError::class)
        ->and(fn() => $fail->assertNotExitCode(3))->toThrow(AssertionFailedError::class);
});

test('expectsOutput 系列按整行/片段断言 stdout', function () {
    $result = testing_command([PHP_BINARY, '-r', 'echo "line1\nline2\n";']);

    expect($result->expectsOutput('line1'))->toBeInstanceOf(CommandResult::class)
        ->and($result->expectsOutputToContain('line'))->toBeInstanceOf(CommandResult::class)
        ->and($result->doesntExpectOutput('not-exists'))->toBeInstanceOf(CommandResult::class)
        ->and(fn() => $result->expectsOutput('not-exists'))->toThrow(AssertionFailedError::class)
        // 整行匹配不区分行内空白
        ->and(fn() => $result->doesntExpectOutput('line1'))->toThrow(AssertionFailedError::class);
});
