<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Template\TemplateCatalogEntry;
use Blokctl\Template\TemplateCatalogResult;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractTemplateCommand extends Command
{
    protected function writeJson(OutputInterface $output, mixed $value): void
    {
        $output->writeln(json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /** @param list<TemplateCatalogEntry> $templates */
    protected function renderTemplates(OutputInterface $output, array $templates): void
    {
        if ($templates === []) {
            $output->writeln('<comment>No templates found.</comment>');
            return;
        }

        $output->writeln(sprintf('<info>Templates (%d)</info>', count($templates)));
        foreach ($templates as $template) {
            $output->writeln('');
            $output->writeln('<info>• ' . $template->name() . '</info>');
            $this->templateDetail($output, 'Category', $template->category());
            $this->templateDetail($output, 'Owner', $template->owner());
            $this->templateDetail($output, 'Tags', implode(', ', $template->tags()));
            $this->templateDetail($output, 'File', $template->path);
        }
    }

    protected function renderErrors(OutputInterface $output, TemplateCatalogResult $result): void
    {
        if ($result->errors === []) {
            return;
        }

        $output->writeln('');
        $output->writeln('<comment>Files skipped because they could not be loaded:</comment>');
        foreach ($result->errors as $error) {
            $output->writeln(sprintf('  - %s: %s', $error['path'], $error['error']));
        }
    }

    protected function stringArgument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);

        return is_string($value) ? $value : '';
    }

    private function templateDetail(OutputInterface $output, string $label, string|null $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $output->writeln(sprintf('  <comment>%s:</comment> %s', $label, $value));
    }
}
