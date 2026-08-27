<?php

namespace app\command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * e2e 演示：成功命令（webmanCommand + CommandResult 断言）
 */
#[AsCommand('e2e:hello', 'e2e demo: output hello')]
class E2eHelloCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::OPTIONAL, 'who to greet', 'world');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('hello ' . $input->getArgument('name'));

        return self::SUCCESS;
    }
}
