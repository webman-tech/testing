<?php

declare(strict_types=1);

namespace WebmanTech\Testing\Database;

/**
 * callable 迁移器（testing 配置 database.migrator 传闭包/可调用数组时的适配）
 */
final class CallableMigrator implements MigratorInterface
{
    /**
     * @param callable(): void $callback
     */
    public function __construct(
        private readonly mixed $callback,
    ) {
    }

    public function migrate(): void
    {
        ($this->callback)();
    }
}
