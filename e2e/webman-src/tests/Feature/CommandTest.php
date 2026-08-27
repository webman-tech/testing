<?php

// webmanCommand + CommandResult：真实 webman CLI 进程的退出码与输出断言

test('webmanCommand 执行成功命令并断言输出', function () {
    $this->webmanCommand('e2e:hello')
        ->assertOk()
        ->expectsOutput('hello world');
});

test('带参数的命令', function () {
    $this->webmanCommand('e2e:hello', 'e2e')
        ->assertOk()
        ->expectsOutput('hello e2e');
});

test('失败命令断言 exitCode', function () {
    $this->webmanCommand('e2e:fail')
        ->assertFailed()
        ->assertExitCode(1);
});
