<?php

declare(strict_types=1);

namespace WebmanTech\Testing\E2eSetup\Command;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * e2e init：生成 e2e 脚手架（e2e-setup.php 应用定义 + app-src 自有代码样例）
 */
final class InitCommand extends Command
{
    public function __construct()
    {
        parent::__construct('init');
    }

    protected function configure(): void
    {
        $this->setDescription('生成 e2e 脚手架（e2e-setup.php 应用定义 + app-src 自有代码样例）')
            ->addOption('framework', null, InputOption::VALUE_REQUIRED, '框架样例（webman|laravel）', 'webman')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, '生成目录（默认 {cwd}/e2e）');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $framework = (string)$input->getOption('framework');
        if (!in_array($framework, ['webman', 'laravel'], true)) {
            throw new InvalidArgumentException("未知框架: {$framework}（可选 webman|laravel）");
        }
        $dir = (string)($input->getOption('dir') ?: getcwd() . '/e2e');

        $stubsDir = __DIR__ . '/../stubs';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        copy($stubsDir . '/e2e-setup.php', $dir . '/e2e-setup.php');
        // 框架样例骨架生成到 {dir}/app-src（与 e2e-setup.php 模板的 src_dir 默认值一致，改名需同步）
        self::copyDir($stubsDir . '/' . $framework, $dir . '/app-src');

        $output->writeln("==> e2e 脚手架已生成:\n"
            . "  {$dir}/e2e-setup.php（应用定义，按需修改被测包与依赖）\n"
            . "  {$dir}/app-src/（自有代码：config/tests，sync 覆盖式同步到应用）\n"
            . '下一步: 修改 e2e-setup.php → vendor/bin/e2e-setup install');

        return Command::SUCCESS;
    }

    /**
     * 递归复制目录（覆盖式）
     */
    private static function copyDir(string $src, string $target): void
    {
        $src = rtrim($src, '/');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $targetPath = $target . '/' . substr((string)$item, strlen($src) + 1);
            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                if (!is_dir(dirname($targetPath))) {
                    mkdir(dirname($targetPath), 0755, true);
                }
                copy((string)$item, $targetPath);
            }
        }
    }
}
