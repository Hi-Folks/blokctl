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
    name: 'template:show',
    description: 'Validate and show metadata for one setup template',
)]
final class TemplateShowCommand extends AbstractTemplateCommand
{
    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Setup template YAML or JSON file')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output template details as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $this->stringArgument($input, 'file');
        try {
            $template = new TemplateCatalog()->load($file);
        } catch (\RuntimeException $runtimeException) {
            if ($input->getOption('json')) {
                $this->writeJson($output, ['valid' => false, 'path' => $file, 'error' => $runtimeException->getMessage()]);
            } else {
                $output->writeln('<error>' . $runtimeException->getMessage() . '</error>');
            }

            return self::FAILURE;
        }

        $data = $template->toArray();
        if ($input->getOption('json')) {
            $this->writeJson($output, $data);
            return self::SUCCESS;
        }

        $output->writeln('<info>' . $template->name() . '</info>');
        $this->line($output, 'File', $template->path);
        $this->line($output, 'Description', $data['description']);
        $this->line($output, 'Category', $data['category']);
        $this->line($output, 'Owner', $data['owner']);
        $this->line($output, 'Tags', implode(', ', $template->tags()));
        $this->line($output, 'Inputs', implode(', ', $template->inputs));
        $this->line($output, 'Target mode', $template->targetMode);
        $this->line($output, 'Extends', implode(', ', $template->extends));
        $this->line($output, 'Validation', 'valid');

        return self::SUCCESS;
    }

    private function line(OutputInterface $output, string $label, mixed $value): void
    {
        if (!is_scalar($value) || (string) $value === '') {
            return;
        }

        $output->writeln(sprintf('<comment>%s:</comment> %s', $label, (string) $value));
    }
}
