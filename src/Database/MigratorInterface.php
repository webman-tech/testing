<?php

declare(strict_types=1);

namespace WebmanTech\Testing\Database;

/**
 * 数据库迁移器（对应 laravel RefreshDatabase 的迁移语义）
 *
 * 真实进程模式下测试进程与 server 进程共享同一数据源，迁移在测试进程内执行一次
 * （进程级一次性），server 进程无需感知。默认实现见 PhinxMigrator（复用被测应用的
 * phinx.php 配置），可通过 testing 配置的 database.migrator 覆盖。
 */
interface MigratorInterface
{
    /**
     * 执行数据库迁移（实现方需保证幂等：已迁移过的迁移不重复执行）
     */
    public function migrate(): void;
}
