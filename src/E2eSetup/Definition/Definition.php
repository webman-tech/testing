<?php

declare(strict_types=1);

namespace WebmanTech\Testing\E2eSetup\Definition;

use InvalidArgumentException;

/**
 * e2e 应用定义：校验 + 规范化。
 *
 * 定义文件（默认 e2e/e2e-setup.php）返回「应用名 => 应用配置」映射（单应用即一个键）。
 * 本类只做配置传导与路径解析，不含任何框架逻辑（框架无关，预留独立迁移）。
 *
 * 应用配置键（normalize 校验后产出 AppDefinition DTO，下游经命名属性访问）：
 *   skeleton            骨架包名（如 workerman/webman、laravel/laravel）或本地骨架目录路径（存在则复制代替 create-project）——必填
 *   skeleton_version    create-project 骨架版本约束（可选，如 laravel/laravel 的 ^12.0）
 *   target_dir / src_dir  应用生成目录 / 自有代码同步源——必填
 *   packages            被测包列表：[['name' => ..., 'path' => ...|'vcs' => ...], ...]；
 *                       path/vcs 省略时默认 path=项目根（baseDir 的父目录），version 默认 dev-main
 *   require / require_dev  额外依赖（默认空，零内置）
 *   require_override    覆盖骨架既有依赖
 *   reinstall_packages  需 Install 落地的包（默认空；webman 插件场景显式声明）
 */
final class Definition
{
    /**
     * 校验并规范化应用定义
     *
     * @param array<mixed, mixed> $definitions 应用名 => 应用配置（定义文件是用户数据，运行时校验）
     * @param string $baseDir 定义文件所在目录（相对路径的解析基准，须为绝对路径）
     * @return array<string, AppDefinition> 规范化后的定义（默认值补齐、相对路径解析为绝对路径）
     */
    public static function normalize(array $definitions, string $baseDir): array
    {
        if ($definitions === []) {
            throw new InvalidArgumentException('应用定义不能为空');
        }
        $normalized = [];
        foreach ($definitions as $name => $definition) {
            if (!is_string($name) || $name === '' || !is_array($definition)) {
                throw new InvalidArgumentException('应用定义必须是「应用名 => 配置数组」的映射');
            }
            $normalized[$name] = self::normalizeApp($name, $definition, $baseDir);
        }

        return $normalized;
    }

    /**
     * @param array<mixed, mixed> $def
     */
    private static function normalizeApp(string $name, array $def, string $baseDir): AppDefinition
    {
        foreach (['skeleton', 'target_dir', 'src_dir'] as $required) {
            if (!is_string($def[$required] ?? null) || $def[$required] === '') {
                throw new InvalidArgumentException("应用「{$name}」缺少必填配置 {$required}（字符串）");
            }
        }

        // 骨架：本地目录（相对 baseDir 解析后存在）则复制代替 create-project
        $skeleton = $def['skeleton'];
        $localSkeleton = self::resolvePath($skeleton, $baseDir);
        if (is_dir($localSkeleton)) {
            $skeleton = $localSkeleton;
        }

        $rawPackages = $def['packages'] ?? [];
        if (!is_array($rawPackages)) {
            throw new InvalidArgumentException("应用「{$name}」的 packages 必须是数组");
        }
        $packages = [];
        foreach ($rawPackages as $package) {
            if (!is_array($package) || !is_string($package['name'] ?? null) || $package['name'] === '') {
                throw new InvalidArgumentException("应用「{$name}」的 packages 元素必须包含非空 name");
            }
            if (isset($package['path']) && isset($package['vcs'])) {
                throw new InvalidArgumentException("应用「{$name}」的包 {$package['name']} 不能同时声明 path 与 vcs");
            }
            $version = isset($package['version']) ? (string)$package['version'] : 'dev-main';
            if (isset($package['path'])) {
                $path = (string)$package['path'];
                if ($path === '') {
                    throw new InvalidArgumentException("应用「{$name}」的包 {$package['name']} 的 path 不能为空");
                }
                $packages[] = new PackageDefinition(
                    name: $package['name'],
                    path: self::resolvePath($path, $baseDir),
                    version: $version,
                );
            } elseif (isset($package['vcs'])) {
                $vcs = (string)$package['vcs'];
                if ($vcs === '') {
                    throw new InvalidArgumentException("应用「{$name}」的包 {$package['name']} 的 vcs 不能为空");
                }
                $packages[] = new PackageDefinition(
                    name: $package['name'],
                    vcs: $vcs,
                    version: $version,
                );
            } else {
                // path/vcs 均未声明：默认 path=项目根（baseDir 的父目录）
                $packages[] = new PackageDefinition(
                    name: $package['name'],
                    path: dirname($baseDir),
                    version: $version,
                );
            }
        }

        return new AppDefinition(
            skeleton: $skeleton,
            targetDir: self::resolvePath($def['target_dir'], $baseDir),
            srcDir: self::resolvePath($def['src_dir'], $baseDir),
            skeletonVersion: isset($def['skeleton_version']) ? (string)$def['skeleton_version'] : '',
            packages: $packages,
            require: self::stringMap($def['require'] ?? []),
            requireDev: self::stringMap($def['require_dev'] ?? []),
            requireOverride: self::stringMap($def['require_override'] ?? []),
            reinstallPackages: self::stringList($def['reinstall_packages'] ?? []),
        );
    }

    /**
     * @param array<string, mixed> $map
     * @return array<string, string>
     */
    private static function stringMap(array $map): array
    {
        $result = [];
        foreach ($map as $key => $value) {
            $result[(string)$key] = (string)$value;
        }

        return $result;
    }

    /**
     * @param array<int, mixed> $list
     * @return array<int, string>
     */
    private static function stringList(array $list): array
    {
        $result = [];
        foreach ($list as $value) {
            if ($value !== '' && $value !== null) {
                $result[] = (string)$value;
            }
        }

        return $result;
    }

    private static function resolvePath(string $path, string $baseDir): string
    {
        return str_starts_with($path, '/') ? $path : rtrim($baseDir, '/') . '/' . $path;
    }
}
