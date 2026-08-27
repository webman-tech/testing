<?php

namespace app\command;

use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * e2e 演示：CLI 进程内写入 sqlite（测试进程 PDO 直连同一文件库跨进程断言）
 */
#[AsCommand('e2e:seed', 'e2e demo: seed sqlite runtime/e2e.sqlite')]
class E2eSeedCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pdo = new PDO('sqlite:' . base_path() . '/runtime/e2e.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            name TEXT NOT NULL,
            deleted_at TEXT NULL
        )');
        $stmt = $pdo->prepare('INSERT INTO users (email, name) VALUES (?, ?)');
        $stmt->execute(['seed@example.com', 'seed-user']);

        $output->writeln('seeded');

        return self::SUCCESS;
    }
}
