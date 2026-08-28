<?php

use WebmanTech\Testing\E2eSetup\Installer\Installer;

/*
 * e2e 安装编排器：命令序列（stub runner 注入，不执行真实命令）、composer.json patch、文件同步。
 */

/**
 * 构造骨架场景：baseDir 下预建 target/（含骨架 composer.json）与 src/（自有代码）
 */
function e2e_installer_scenario(): string
{
    $baseDir = e2e_tmp_dir('installer');
    e2e_installer_skeleton($baseDir);
    mkdir($baseDir . '/src/config', 0755, true);
    file_put_contents($baseDir . '/src/config/testing.php', '<?php return [];');

    return $baseDir;
}

test('install 全流程：命令序列 + patch composer.json + 同步 src', function () {
    $baseDir = e2e_installer_scenario();
    $commands = [];
    $runner = function (array $command, ?string $cwd, array $env) use (&$commands, $baseDir): void {
        $commands[] = ['command' => $command, 'cwd' => $cwd, 'env' => $env];
        // 模拟 create-project 副作用：重建 target 骨架（真实流程中 removeDir 已删除旧 target）
        if (($command[1] ?? null) === 'create-project') {
            e2e_installer_skeleton($baseDir);
        }
    };

    $installer = new Installer([
        'webman' => [
            'skeleton' => 'workerman/webman',
            'skeleton_version' => '^2.0',
            'target_dir' => 'target',
            'src_dir' => 'src',
            'packages' => [
                ['name' => 'webman-tech/testing'],
                ['name' => 'vendor/other'],
            ],
            'require' => ['webman/crontab' => '^1.0'],
            'require_dev' => ['pestphp/pest' => '^3.8'],
            'require_override' => ['monolog/monolog' => '^3.0'],
            'reinstall_packages' => ['webman/console'],
        ],
    ], $baseDir, $runner);

    $installer->install('webman');

    // 命令序列：create-project（skeleton_version 在 directory 之后）→ update → reinstall（目录删除为 PHP 原生实现，非命令）
    expect(array_column($commands, 'command'))->toBe([
        ['composer', 'create-project', 'workerman/webman', $baseDir . '/target', '^2.0', '--no-interaction', '--no-progress'],
        ['composer', 'update', '--no-interaction', '--no-progress'],
        ['composer', 'reinstall', '--no-interaction', '--no-progress', 'webman/console'],
    ]);
    // update/reinstall 在 target 目录执行并注入 COMPOSER_ROOT_VERSION（path repository CI 兜底）
    expect($commands[1]['cwd'])->toBe($baseDir . '/target')
        ->and($commands[1]['env'])->toBe(['COMPOSER_ROOT_VERSION' => 'dev-main'])
        ->and($commands[2]['cwd'])->toBe($baseDir . '/target');

    // patch composer.json：依赖合并与覆盖、Tests 命名空间、allow-plugins、稳定性、同 path 包合并 repository
    $patched = json_decode((string)file_get_contents($baseDir . '/target/composer.json'), true);
    expect($patched['require'])->toBe(['php' => '^8.2', 'monolog/monolog' => '^3.0', 'webman/crontab' => '^1.0'])
        ->and($patched['require-dev'])->toBe(['pestphp/pest' => '^3.8'])
        ->and($patched['autoload-dev']['psr-4'])->toBe(['Tests\\' => 'tests/'])
        ->and($patched['config']['allow-plugins'])->toBe(['other/plugin' => true, 'pestphp/pest-plugin' => true])
        ->and($patched['minimum-stability'])->toBe('dev')
        ->and($patched['prefer-stable'])->toBe(true)
        ->and($patched['repositories'])->toBe([
            [
                'type' => 'path',
                'url' => dirname($baseDir),
                'options' => [
                    'symlink' => true,
                    'versions' => ['webman-tech/testing' => 'dev-main', 'vendor/other' => 'dev-main'],
                ],
            ],
        ]);

    // sync：src 文件覆盖式复制到 target
    expect(file_get_contents($baseDir . '/target/config/testing.php'))->toBe('<?php return [];');
});

test('create-project 不带 skeleton_version 与 reinstall 为空时跳过', function () {
    $baseDir = e2e_installer_scenario();
    $commands = [];
    $runner = function (array $command, ?string $cwd, array $env) use (&$commands, $baseDir): void {
        $commands[] = $command;
        // 模拟 create-project 副作用（同 install 全流程测试）
        if (($command[1] ?? null) === 'create-project') {
            e2e_installer_skeleton($baseDir);
        }
    };

    $installer = new Installer([
        'app' => [
            'skeleton' => 'workerman/webman',
            'target_dir' => 'target',
            'src_dir' => 'src',
        ],
    ], $baseDir, $runner);

    $installer->install('app');

    $commandLines = array_map(static fn(array $c): string => implode(' ', $c), $commands);
    expect($commandLines)->toHaveCount(2)
        ->and($commandLines[0])->toBe('composer create-project workerman/webman ' . $baseDir . '/target --no-interaction --no-progress')
        ->and($commandLines)->not->toContain('composer reinstall --no-interaction --no-progress webman/console');
});

test('skeleton 为本地目录时复制代替 create-project', function () {
    $baseDir = e2e_tmp_dir('installer-local');
    mkdir($baseDir . '/my-skeleton/config', 0755, true);
    file_put_contents($baseDir . '/my-skeleton/composer.json', '{"name":"local-skeleton"}');
    mkdir($baseDir . '/target', 0755, true);
    // 预置旧文件与符号链接：install 应先删除重建 target（PHP 原生 removeDir，非 rm 命令）
    file_put_contents($baseDir . '/target/stale.txt', 'stale');
    mkdir($baseDir . '/target/old-dir', 0755, true);
    file_put_contents($baseDir . '/target/old-dir/keep.txt', 'keep');
    // path repository 场景 vendor 下的包目录是 symlink，removeDir 须只删链接本身（Windows 无权限时静默跳过）
    @symlink($baseDir . '/target/old-dir', $baseDir . '/target/stale-link');
    file_put_contents($baseDir . '/target/composer.json', '{}');
    mkdir($baseDir . '/src', 0755, true);

    $commands = [];
    $runner = function (array $command, ?string $cwd, array $env) use (&$commands): void {
        $commands[] = $command;
    };
    $installer = new Installer([
        'app' => [
            'skeleton' => 'my-skeleton',
            'target_dir' => 'target',
            'src_dir' => 'src',
        ],
    ], $baseDir, $runner);

    $installer->install('app');

    $commandLines = array_map(static fn(array $c): string => implode(' ', $c), $commands);
    expect($commandLines)->toHaveCount(1) // update（本地骨架复制与目录删除均为 PHP 原生实现，非命令）
        ->and(implode(' ', $commandLines))->not->toContain('create-project')
        // 骨架目录被复制并作为 patch 基础（patch 发生在复制之后）
        ->and(file_get_contents($baseDir . '/target/composer.json'))->toContain('local-skeleton')
        // 预置的旧文件/旧目录/符号链接均已被删除重建（removeDir 真实执行）
        ->and(is_file($baseDir . '/target/stale.txt'))->toBeFalse()
        ->and(is_dir($baseDir . '/target/old-dir'))->toBeFalse()
        ->and(is_link($baseDir . '/target/stale-link'))->toBeFalse();
});

test('--vcs 时未声明 path/vcs 的包改经 GitHub VCS 安装（显式 vcs 保留）', function () {
    $baseDir = e2e_installer_scenario();
    // 模拟 create-project 副作用（同 install 全流程测试）
    $runner = function (array $command, ?string $cwd, array $env) use ($baseDir): void {
        if (($command[1] ?? null) === 'create-project') {
            e2e_installer_skeleton($baseDir);
        }
    };
    $installer = new Installer([
        'app' => [
            'skeleton' => 'workerman/webman',
            'target_dir' => 'target',
            'src_dir' => 'src',
            'packages' => [
                ['name' => 'webman-tech/testing'],
                ['name' => 'vendor/custom', 'vcs' => 'https://custom.example.com/vendor/custom.git'],
            ],
        ],
    ], $baseDir, $runner);

    $installer->install('app', ['vcs' => true]);

    $patched = json_decode((string)file_get_contents($baseDir . '/target/composer.json'), true);
    expect($patched['repositories'])->toBe([
        ['type' => 'vcs', 'url' => 'https://github.com/webman-tech/testing.git'],
        ['type' => 'vcs', 'url' => 'https://custom.example.com/vendor/custom.git'],
    ]);
});

test('sync 覆盖式同步 src 到已安装应用', function () {
    $baseDir = e2e_installer_scenario();
    $installer = new Installer([
        'app' => [
            'skeleton' => 'workerman/webman',
            'target_dir' => 'target',
            'src_dir' => 'src',
        ],
    ], $baseDir, static fn(array $command, ?string $cwd, array $env) => null);

    $installer->sync('app');

    expect(file_get_contents($baseDir . '/target/config/testing.php'))->toBe('<?php return [];');
});

test('sync 在目标不存在时抛可读异常', function () {
    $baseDir = e2e_tmp_dir('installer-sync');
    mkdir($baseDir . '/src', 0755, true);
    $installer = new Installer([
        'app' => [
            'skeleton' => 'workerman/webman',
            'target_dir' => 'target',
            'src_dir' => 'src',
        ],
    ], $baseDir, static fn(array $command, ?string $cwd, array $env) => null);

    expect(fn() => $installer->sync('app'))
        ->toThrow(RuntimeException::class, '目标目录不存在');
});

test('未知应用抛可读异常', function () {
    $baseDir = e2e_tmp_dir('installer-unknown');
    $installer = new Installer([
        'app' => ['skeleton' => 'workerman/webman', 'target_dir' => 't', 'src_dir' => 's'],
    ], $baseDir, static fn(array $command, ?string $cwd, array $env) => null);

    expect(fn() => $installer->install('nope'))
        ->toThrow(RuntimeException::class, '未知应用: nope');
});
