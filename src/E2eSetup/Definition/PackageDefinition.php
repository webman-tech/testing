<?php

declare(strict_types=1);

namespace WebmanTech\Testing\E2eSetup\Definition;

/**
 * e2e 被测包定义（readonly DTO，命名参数构造）。
 *
 * path 与 vcs 互斥（Definition::normalize 保证至多一个非空）：path 为本地目录
 * （path repository），vcs 为远程仓库地址（vcs repository）。
 */
final class PackageDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $path = null,
        public readonly ?string $vcs = null,
        public readonly string $version = 'dev-main',
    ) {
    }
}
