<?php

use WebmanTech\Testing\E2eSetup\Console;

/*
 * e2e 命令入口：setup 域脚手架生成、定义文件加载与命令装配、错误路径。
 */

test('init 生成 webman 脚手架（默认框架）', function () {
    $dir = e2e_tmp_dir('console-init-webman');

    $exitCode = Console::run(['e2e-setup', 'init', '--framework=webman', '--dir=' . $dir]);

    expect($exitCode)->toBe(0)
        ->and(is_file($dir . '/e2e-setup.php'))->toBeTrue()
        ->and(is_file($dir . '/app-src/phpunit.xml'))->toBeTrue()
        ->and(is_file($dir . '/app-src/config/process.php'))->toBeTrue()
        ->and(is_file($dir . '/app-src/config/testing.php'))->toBeTrue()
        ->and(is_file($dir . '/app-src/tests/Pest.php'))->toBeTrue()
        ->and(is_file($dir . '/app-src/tests/TestCase.php'))->toBeTrue()
        // 模板内容抽查：应用定义含被测包占位、测试基类继承组件 TestCase
        ->and((string)file_get_contents($dir . '/e2e-setup.php'))->toContain('vendor/your-package')
        ->and((string)file_get_contents($dir . '/app-src/tests/TestCase.php'))->toContain('WebmanTech\\Testing\\TestCase');
});

test('init 生成 laravel 脚手架', function () {
    $dir = e2e_tmp_dir('console-init-laravel');

    $exitCode = Console::run(['e2e-setup', 'init', '--framework=laravel', '--dir=' . $dir]);

    expect($exitCode)->toBe(0)
        ->and(is_file($dir . '/e2e-setup.php'))->toBeTrue()
        ->and(is_file($dir . '/app-src/tests/TestCase.php'))->toBeTrue()
        ->and(is_file($dir . '/app-src/tests/Pest.php'))->toBeTrue()
        // laravel 测试基类继承骨架自带基类；不含 webman 样例 config
        ->and((string)file_get_contents($dir . '/app-src/tests/TestCase.php'))->toContain('Illuminate\\Foundation\\Testing\\TestCase')
        ->and(is_dir($dir . '/app-src/config'))->toBeFalse();
});

test('init 未知框架或未知选项返回非零', function () {
    $dir = e2e_tmp_dir('console-init-bad');

    expect(Console::run(['e2e-setup', 'init', '--framework=thinkphp', '--dir=' . $dir]))->toBe(1)
        ->and(Console::run(['e2e-setup', 'init', '--foo', '--dir=' . $dir]))->toBe(1);
});

test('install 加载定义文件（相对路径基于定义文件目录解析）并透传命令', function () {
    $baseDir = e2e_tmp_dir('console-install');
    e2e_installer_skeleton($baseDir);
    mkdir($baseDir . '/src', 0755, true);
    $configFile = $baseDir . '/e2e-setup.php';
    // rector.php 风格类写法（定义文件真实形态）
    file_put_contents($configFile, '<?php
use WebmanTech\Testing\E2eSetup\Definition\AppConfig;
use WebmanTech\Testing\E2eSetup\SetupConfig;
return SetupConfig::configure()->app("webman", AppConfig::configure()->skeleton("workerman/webman")->targetDir("target")->srcDir("src"));');

    $commands = [];
    $runner = function (array $command, ?string $cwd, array $env) use (&$commands, $baseDir): void {
        $commands[] = $command;
        // 模拟 create-project 副作用（真实流程中 removeDir 已删除预建旧 target）
        if (($command[1] ?? null) === 'create-project') {
            e2e_installer_skeleton($baseDir);
        }
    };

    $exitCode = Console::run(['e2e-setup', 'install', '--config', $configFile], $runner);

    expect($exitCode)->toBe(0)
        ->and(implode(' ', $commands[0]))->toBe('composer create-project workerman/webman ' . $baseDir . '/target --no-interaction --no-progress')
        // --vcs 透传：patch 生成 vcs repository
        ->and(json_decode((string)file_get_contents($baseDir . '/target/composer.json'), true)['repositories'] ?? null)->toBeNull();
});

test('install --vcs 生成 GitHub VCS repository', function () {
    $baseDir = e2e_tmp_dir('console-install-vcs');
    e2e_installer_skeleton($baseDir);
    mkdir($baseDir . '/src', 0755, true);
    $configFile = $baseDir . '/e2e-setup.php';
    // 旧数组写法仍兼容（fromConfigFile 统一转 SetupConfig 数组后走同一校验）
    file_put_contents($configFile, '<?php return ["webman" => ["skeleton" => "workerman/webman", "target_dir" => "target", "src_dir" => "src", "packages" => [["name" => "webman-tech/testing"]]]];');

    $runner = function (array $command, ?string $cwd, array $env) use ($baseDir): void {
        // 模拟 create-project 副作用（真实流程中 removeDir 已删除预建旧 target）
        if (($command[1] ?? null) === 'create-project') {
            e2e_installer_skeleton($baseDir);
        }
    };
    $exitCode = Console::run(['e2e-setup', 'install', '--config=' . $configFile, '--vcs'], $runner);

    $patched = json_decode((string)file_get_contents($baseDir . '/target/composer.json'), true);
    expect($exitCode)->toBe(0)
        ->and($patched['repositories'])->toBe([
            ['type' => 'vcs', 'url' => 'https://github.com/webman-tech/testing.git'],
        ]);
});

test('定义文件不存在返回非零', function () {
    expect(Console::run(['e2e-setup', 'install', '--config', '/nonexistent/e2e-setup.php'], static fn(array $command, ?string $cwd, array $env) => null))
        ->toBe(1);
});

test('未知命令返回非零', function () {
    expect(Console::run(['e2e-setup', 'unknown-cmd']))->toBe(1);
});
