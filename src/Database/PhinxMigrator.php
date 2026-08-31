<?php

declare(strict_types=1);

namespace WebmanTech\Testing\Database;

use Phinx\Config\Config;
use Phinx\Migration\Manager;
use PDO;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * 基于 robmorgan/phinx 的迁移器（复用被测应用的 phinx.php 配置）
 *
 * 应用侧 phinx.php 通常已配置连接（Eloquent PDO / env 化切换测试库），测试进程内执行
 * 与 CLI（composer phinx migrate）同一份迁移，且与 webman 进程共享同一数据源；
 * phinxlog 已记录迁移时 Manager 自动跳过，天然幂等。
 *
 * $connection 可选：传入时覆盖 phinx.php 中对应 environment 的连接（如 memory 模式
 * 把迁移目标指向测试进程的内存库，此时不依赖应用 phinx.php 的连接配置）。
 *
 * phinx 为可选依赖：未安装时 migrate() 抛可读异常（组件不硬依赖）。
 */
final class PhinxMigrator implements MigratorInterface
{
    public function __construct(
        private readonly string $configFile,
        private readonly string $environment = 'development',
        private readonly ?PDO $connection = null,
    ) {
    }

    public function migrate(): void
    {
        if (!class_exists(Manager::class)) {
            throw new RuntimeException(
                '未检测到 phinx（robmorgan/phinx），无法执行数据库迁移：'
                . '请安装 robmorgan/phinx，或在 testing 配置的 database.migrator 中提供自定义迁移器',
            );
        }
        if (!is_file($this->configFile)) {
            throw new RuntimeException(sprintf(
                'phinx 配置文件不存在: %s（可经 testing 配置的 database.phinx.configFile 指定）',
                $this->configFile,
            ));
        }

        $config = Config::fromPhp($this->configFile);
        if ($this->connection !== null) {
            // 注意：不能嵌套写 $config['environments'][$env]['connection']——ArrayAccess
            // 的嵌套修改无效（Indirect modification has no effect），须整体取出改完再写回
            $environments = $config['environments'];
            $environments[$this->environment]['connection'] = $this->connection;
            $config['environments'] = $environments;
        }
        $manager = new Manager($config, new ArrayInput([]), new NullOutput());
        $manager->migrate($this->environment);
    }
}
