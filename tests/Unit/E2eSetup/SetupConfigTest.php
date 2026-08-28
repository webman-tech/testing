<?php

use WebmanTech\Testing\E2eSetup\Definition\AppConfig;
use WebmanTech\Testing\E2eSetup\Definition\Definition;
use WebmanTech\Testing\E2eSetup\Definition\PackageDefinition;
use WebmanTech\Testing\E2eSetup\SetupConfig;

/*
 * e2e 应用定义类写法（rector.php 风格）：SetupConfig/AppConfig → 定义数组的转换与默认值语义。
 */

test('SetupConfig 累积多应用并转换为定义数组（默认值与数组写法一致）', function () {
    $definitions = SetupConfig::configure()
        ->app('a', AppConfig::configure()->skeleton('x/a')->targetDir('ta')->srcDir('sa'))
        ->app('b', AppConfig::configure()->skeleton('x/b', '^2.0')->targetDir('tb')->srcDir('sb'))
        ->toArray();

    expect($definitions)->toBe([
        'a' => [
            'skeleton' => 'x/a',
            'target_dir' => 'ta',
            'src_dir' => 'sa',
            'packages' => [],
            'require' => [],
            'require_dev' => [],
            'require_override' => [],
            'reinstall_packages' => [],
        ],
        'b' => [
            'skeleton' => 'x/b',
            'skeleton_version' => '^2.0',
            'target_dir' => 'tb',
            'src_dir' => 'sb',
            'packages' => [],
            'require' => [],
            'require_dev' => [],
            'require_override' => [],
            'reinstall_packages' => [],
        ],
    ]);
});

test('AppConfig::package 累积条目（默认 path=项目根、默认 version=dev-main 省略）', function () {
    $app = AppConfig::configure()
        ->skeleton('workerman/webman')
        ->targetDir('webman')
        ->srcDir('app-src')
        ->package('vendor/pkg-a')
        ->package('vendor/pkg-b', '../packages')
        ->package('vendor/pkg-c', null, 'https://github.com/vendor/pkg-c.git')
        ->package('vendor/pkg-d', '/abs/path', null, '^2.0')
        ->toArray();

    expect($app['packages'])->toBe([
        ['name' => 'vendor/pkg-a'],
        ['name' => 'vendor/pkg-b', 'path' => '../packages'],
        ['name' => 'vendor/pkg-c', 'vcs' => 'https://github.com/vendor/pkg-c.git'],
        ['name' => 'vendor/pkg-d', 'path' => '/abs/path', 'version' => '^2.0'],
    ]);
});

test('AppConfig 依赖族配置与手写数组同构（Definition::normalize 可消化）', function () {
    $definitions = SetupConfig::configure()
        ->app('webman', AppConfig::configure()
            ->skeleton('workerman/webman')
            ->targetDir('webman')
            ->srcDir('app-src')
            ->package('vendor/pkg')
            ->require(['a/b' => '^1.0'])
            ->requireDev(['c/d' => '^2.0'])
            ->requireOverride(['e/f' => '^3.0'])
            ->reinstallPackages(['webman/console'])
        )
        ->toArray();

    $normalized = Definition::normalize($definitions, '/tmp/base')['webman'];
    expect($normalized->skeleton)->toBe('workerman/webman')
        ->and($normalized->targetDir)->toBe('/tmp/base/webman')
        ->and($normalized->require)->toBe(['a/b' => '^1.0'])
        ->and($normalized->requireDev)->toBe(['c/d' => '^2.0'])
        ->and($normalized->requireOverride)->toBe(['e/f' => '^3.0'])
        ->and($normalized->reinstallPackages)->toBe(['webman/console'])
        ->and($normalized->packages)->toEqual([new PackageDefinition(name: 'vendor/pkg', path: '/tmp')]);
});
