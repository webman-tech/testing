<?php

declare(strict_types=1);

namespace WebmanTech\Testing\E2eSetup\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WebmanTech\Testing\E2eSetup\Installer\Installer;

/**
 * e2e sync：仅同步自有代码（dev 快速迭代，须先 install）
 */
final class SyncCommand extends Command
{
    /** @var \Closure(array<int, string>, ?string, array<string, string>): void|null 透传 Installer（测试注入用） */
    private readonly ?\Closure $runner;

    /**
     * @param (callable(array<int, string>, ?string, array<string, string>): void)|null $runner
     */
    public function __construct(?callable $runner = null)
    {
        parent::__construct('sync');
        // 属性类型不支持 callable，统一转为 Closure
        $this->runner = $runner !== null ? \Closure::fromCallable($runner) : null;
    }

    protected function configure(): void
    {
        $this->setDescription('仅同步自有代码到已安装应用（dev 快速迭代）')
            ->addArgument('app', InputArgument::OPTIONAL, '应用名（省略时同步定义中全部应用）')
            ->addOption('config', null, InputOption::VALUE_REQUIRED, '应用定义文件（默认 {cwd}/e2e/e2e-setup.php）');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $installer = Installer::fromConfigFile(
            (string)($input->getOption('config') ?: getcwd() . '/e2e/e2e-setup.php'),
            $this->runner,
        );

        $apps = $input->getArgument('app') !== null ? [(string)$input->getArgument('app')] : $installer->appNames();
        foreach ($apps as $app) {
            $installer->sync($app);
        }

        return Command::SUCCESS;
    }
}
