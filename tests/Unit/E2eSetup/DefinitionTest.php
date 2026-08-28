<?php

use WebmanTech\Testing\E2eSetup\Definition\Definition;
use WebmanTech\Testing\E2eSetup\Definition\PackageDefinition;

/*
 * e2e 应用定义：校验、默认值补齐、路径解析与本地骨架判定。
 */

test('应用定义为空或非映射时报可读异常', function () {
    expect(fn() => Definition::normalize([], '/tmp/base'))
        ->toThrow(InvalidArgumentException::class, '应用定义不能为空')
        ->and(fn() => Definition::normalize(['webman' => 'not-array'], '/tmp/base'))
        ->toThrow(InvalidArgumentException::class, '应用名 => 配置数组');
});

test('缺少必填配置（skeleton/target_dir/src_dir）报可读异常', function (string $missing) {
    $def = [
        'skeleton' => 'workerman/webman',
        'target_dir' => 'webman',
        'src_dir' => 'app-src',
    ];
    unset($def[$missing]);

    expect(fn() => Definition::normalize(['webman' => $def], '/tmp/base'))
        ->toThrow(InvalidArgumentException::class, "缺少必填配置 {$missing}");
})->with(['skeleton', 'target_dir', 'src_dir']);

test('packages 元素缺 name 或 path/vcs 并存时报可读异常', function () {
    $def = fn(array $packages) => ['webman' => [
        'skeleton' => 'x',
        'target_dir' => 't',
        'src_dir' => 's',
        'packages' => $packages,
    ]];

    expect(fn() => Definition::normalize($def([['path' => '/tmp']]), '/tmp/base'))
        ->toThrow(InvalidArgumentException::class, 'packages 元素必须包含非空 name')
        ->and(fn() => Definition::normalize($def([['name' => 'a', 'path' => '/tmp', 'vcs' => 'https://x']]), '/tmp/base'))
        ->toThrow(InvalidArgumentException::class, '不能同时声明 path 与 vcs');
});

test('默认值补齐与路径解析（相对 baseDir、packages 默认 path=项目根）', function () {
    $baseDir = '/tmp/base';
    $normalized = Definition::normalize([
        'webman' => [
            'skeleton' => 'workerman/webman',
            'target_dir' => 'webman',
            'src_dir' => 'app-src',
            'packages' => [
                ['name' => 'vendor/pkg'],
                ['name' => 'vendor/pkg2', 'path' => '../packages'],
                ['name' => 'vendor/pkg3', 'vcs' => 'https://github.com/vendor/pkg3.git'],
                ['name' => 'vendor/pkg4', 'path' => '/abs/path'],
            ],
            'require' => ['a/b' => '^1.0'],
        ],
    ], $baseDir)['webman'];

    expect($normalized->targetDir)->toBe('/tmp/base/webman')
        ->and($normalized->srcDir)->toBe('/tmp/base/app-src')
        ->and($normalized->skeleton)->toBe('workerman/webman')
        ->and($normalized->skeletonVersion)->toBe('')
        ->and($normalized->require)->toBe(['a/b' => '^1.0'])
        ->and($normalized->requireDev)->toBe([])
        ->and($normalized->requireOverride)->toBe([])
        ->and($normalized->reinstallPackages)->toBe([])
        ->and($normalized->packages)->toEqual([
            new PackageDefinition(name: 'vendor/pkg', path: '/tmp'),
            new PackageDefinition(name: 'vendor/pkg2', path: '/tmp/base/../packages'),
            new PackageDefinition(name: 'vendor/pkg3', vcs: 'https://github.com/vendor/pkg3.git'),
            new PackageDefinition(name: 'vendor/pkg4', path: '/abs/path'),
        ]);
});

test('多应用定义与 version 保留', function () {
    $normalized = Definition::normalize([
        'a' => ['skeleton' => 'x/a', 'target_dir' => 'a', 'src_dir' => 'sa', 'packages' => [['name' => 'p', 'version' => '^2.0']]],
        'b' => ['skeleton' => 'x/b', 'target_dir' => 'b', 'src_dir' => 'sb'],
    ], '/tmp/base');

    expect(array_keys($normalized))->toBe(['a', 'b'])
        ->and($normalized['a']->packages)->toEqual([new PackageDefinition(name: 'p', path: '/tmp', version: '^2.0')]);
});

test('skeleton 为本地目录时解析为绝对路径（复制代替 create-project）', function () {
    $baseDir = e2e_tmp_dir('definition');
    mkdir($baseDir . '/local-skeleton', 0755, true);

    $normalized = Definition::normalize([
        'app' => ['skeleton' => 'local-skeleton', 'target_dir' => 't', 'src_dir' => 's'],
    ], $baseDir)['app'];

    expect($normalized->skeleton)->toBe($baseDir . '/local-skeleton');
});
