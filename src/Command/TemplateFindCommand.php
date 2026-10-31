<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Template\TemplateCatalog;
use Blokctl\Template\TemplateCatalogEntry;
use Blokctl\Template\TemplateCatalogResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'template:find',
    description: 'Find setup templates by metadata',
)]
final class TemplateFindCommand extends AbstractTemplateCommand
{
    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('directory', InputArgument::OPTIONAL, 'Directory containing setup templates', '.')
            ->addOption('tag', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Required template tag (repeatable)')
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'Exact template category')
            ->addOption('owner', null, InputOption::VALUE_REQUIRED, 'Exact template owner')
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Text in name, description, metadata, tags, or path')
            ->addOption('recursive', 'r', InputOption::VALUE_NONE, 'Scan subdirectories')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output matching templates and skipped-file errors as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $directory = $this->stringArgument($input, 'directory');
        try {
            $scanned = new TemplateCatalog()->scan($directory, (bool) $input->getOption('recursive'));
        } catch (\RuntimeException $runtimeException) {
            if ($input->getOption('json')) {
                $this->writeJson($output, ['templates' => [], 'errors' => [['path' => $directory, 'error' => $runtimeException->getMessage()]]]);
            } else {
                $output->writeln('<error>' . $runtimeException->getMessage() . '</error>');
            }

            return self::FAILURE;
        }

        /** @var list<string> $tags */
        $tags = $input->getOption('tag');
        $category = $this->nullableOption($input, 'category');
        $owner = $this->nullableOption($input, 'owner');
        $search = $this->nullableOption($input, 'search');
        $templates = array_values(array_filter(
            $scanned->templates,
            fn(TemplateCatalogEntry $template): bool => $this->matches($template, $tags, $category, $owner, $search),
        ));
        $result = new TemplateCatalogResult($templates, $scanned->errors);

        if ($input->getOption('json')) {
            $this->writeJson($output, $result->toArray());
        } else {
            $this->renderTemplates($output, $templates);
            $this->renderErrors($output, $result);
        }

        return self::SUCCESS;
    }

    /** @param list<string> $tags */
    private function matches(
        TemplateCatalogEntry $template,
        array $tags,
        string|null $category,
        string|null $owner,
        string|null $search,
    ): bool {
        $templateTags = array_map(strtolower(...), $template->tags());
        foreach ($tags as $tag) {
            if (!in_array(strtolower($tag), $templateTags, true)) {
                return false;
            }
        }

        if ($category !== null && strcasecmp($template->category() ?? '', $category) !== 0) {
            return false;
        }

        if ($owner !== null && strcasecmp($template->owner() ?? '', $owner) !== 0) {
            return false;
        }

        if ($search !== null) {
            $haystack = implode(' ', [
                $template->name(),
                $template->description() ?? '',
                $template->category() ?? '',
                $template->owner() ?? '',
                implode(' ', $template->tags()),
                $template->path,
            ]);
            if (stripos($haystack, $search) === false) {
                return false;
            }
        }

        return true;
    }

    private function nullableOption(InputInterface $input, string $name): string|null
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
