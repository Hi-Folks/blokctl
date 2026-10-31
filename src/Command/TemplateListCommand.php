<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Template\TemplateCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'template:list',
    description: 'List setup templates found in a directory',
)]
final class TemplateListCommand extends AbstractTemplateCommand
{
    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('directory', InputArgument::OPTIONAL, 'Directory containing setup templates', '.')
            ->addOption('recursive', 'r', InputOption::VALUE_NONE, 'Scan subdirectories')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output templates and skipped-file errors as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $directory = $this->stringArgument($input, 'directory');
        try {
            $result = new TemplateCatalog()->scan($directory, (bool) $input->getOption('recursive'));
        } catch (\RuntimeException $runtimeException) {
            if ($input->getOption('json')) {
                $this->writeJson($output, ['templates' => [], 'errors' => [['path' => $directory, 'error' => $runtimeException->getMessage()]]]);
            } else {
                $output->writeln('<error>' . $runtimeException->getMessage() . '</error>');
            }

            return self::FAILURE;
        }

        if ($input->getOption('json')) {
            $this->writeJson($output, $result->toArray());
        } else {
            $this->renderTemplates($output, $result->templates);
            $this->renderErrors($output, $result);
        }

        return self::SUCCESS;
    }
}
