<?php

declare(strict_types=1);

namespace WebmanTech\Testing\E2eSetup\Definition;

/**
 * e2e 应用定义（readonly DTO，命名参数构造）。
 *
 * 由 Definition::normalize() 校验并产出（定义文件仍是「应用名 => 配置数组」的用户数据），
 * 下游（Installer 等）一律经命名属性访问，不再用数组 key 魔法字符串。
 */
final class AppDefinition
{
    /**
     * @param array<int, PackageDefinition> $packages 被测包列表（path/vcs 已互斥解析，version 默认 dev-main）
     * @param array<string, string> $require 额外依赖（默认空，零内置）
     * @param array<string, string> $requireDev 额外 dev 依赖
     * @param array<string, string> $requireOverride 覆盖骨架既有依赖
     * @param array<int, string> $reinstallPackages 需 Install 落地的包（默认空；webman 插件场景显式声明）
     */
    public function __construct(
        public readonly string $skeleton,
        public readonly string $targetDir,
        public readonly string $srcDir,
        public readonly string $skeletonVersion = '',
        public readonly array $packages = [],
        public readonly array $require = [],
        public readonly array $requireDev = [],
        public readonly array $requireOverride = [],
        public readonly array $reinstallPackages = [],
    ) {
    }
}
