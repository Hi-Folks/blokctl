<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Story\StoriesBulkCreateAction;
use Blokctl\Action\Story\StoryCreateAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\text;

#[AsCommand(
    name: 'stories:bulk-create',
    description: 'Create stories from JSON files in a directory',
)]
class StoriesBulkCreateCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addArgument('directory', InputArgument::OPTIONAL, 'Directory containing JSON files')
            ->addOption('recursive', 'r', InputOption::VALUE_NONE, 'Walk subdirectories recursively')
            ->addOption('pattern', null, InputOption::VALUE_REQUIRED, 'Glob pattern to match files', '*.json')
            ->addOption('parent-slug', null, InputOption::VALUE_REQUIRED, 'Parent folder slug (applied to all stories)')
            ->addOption('parent-id', null, InputOption::VALUE_REQUIRED, 'Parent folder numeric ID (default: 0 for root)')
            ->addOption('publish', null, InputOption::VALUE_NONE, 'Publish each story immediately after creation');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $directory */
        $directory = $input->getArgument('directory');
        /** @var string|null $parentSlug */
        $parentSlug = $input->getOption('parent-slug');
        /** @var string|null $parentIdRaw */
        $parentIdRaw = $input->getOption('parent-id');
        /** @var string $pattern */
        $pattern = $input->getOption('pattern');
        $recursive = (bool) $input->getOption('recursive');
        $publish = (bool) $input->getOption('publish');

        if ($parentSlug && $parentIdRaw) {
            Render::error('Provide only one of --parent-slug or --parent-id');
            return self::FAILURE;
        }

        if (empty($directory) && !$input->getOption('no-interaction')) {
            $directory = text(
                label: 'Directory containing JSON files',
                placeholder: 'E.g. ./content/stories',
                required: true,
            );
        }

        if (empty($directory)) {
            Render::error('Directory is required.');
            return self::FAILURE;
        }

        Render::titleSection('Bulk create stories from ' . $directory);

        try {
            $parentId = 0;
            if ($parentSlug !== null) {
                $parentId = new StoryCreateAction($this->client)
                    ->resolveParentBySlug($this->spaceId, $parentSlug);
            } elseif ($parentIdRaw !== null) {
                $parentId = (int) $parentIdRaw;
            }

            $result = new StoriesBulkCreateAction($this->client)->execute(
                spaceId: $this->spaceId,
                directory: $directory,
                recursive: $recursive,
                parentId: $parentId,
                publish: $publish,
                pattern: $pattern,
            );
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        foreach ($result->created as $entry) {
            Render::log(
                'Created "' . $entry['name'] . '" (slug: ' . $entry['fullSlug'] . ', id: ' . $entry['id'] . ') from ' . $entry['file'],
            );
        }

        foreach ($result->errors as $error) {
            Render::error($error['file'] . ': ' . $error['error']);
        }

        Render::title('Summary');
        Render::labelValue('Created', (string) $result->count());
        Render::labelValue('Errors', (string) $result->errorCount());

        return $result->errorCount() > 0 && $result->count() === 0
            ? self::FAILURE
            : Command::SUCCESS;
    }
}
