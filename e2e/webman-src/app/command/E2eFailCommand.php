<?php

namespace app\command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * e2e 演示：失败命令（assertFailed/assertExitCode 断言）
 */
#[AsCommand('e2e:fail', 'e2e demo: always fail')]
class E2eFailCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<error>something failed</error>');

        return self::FAILURE;
    }
}
