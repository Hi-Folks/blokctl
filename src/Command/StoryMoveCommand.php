<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Story\StoryMoveAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[AsCommand(
    name: 'story:move',
    description: 'Move a story to a different folder',
)]
class StoryMoveCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('by-slug', null, InputOption::VALUE_REQUIRED, 'Find story by full slug')
            ->addOption('by-id', null, InputOption::VALUE_REQUIRED, 'Find story by numeric ID')
            ->addOption('to-folder-slug', null, InputOption::VALUE_REQUIRED, 'Target folder slug (e.g. archived/authors)')
            ->addOption('to-folder-id', null, InputOption::VALUE_REQUIRED, 'Target folder numeric ID (use 0 for root)')
        ;
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $storySlug */
        $storySlug = $input->getOption('by-slug');
        /** @var string|null $storyId */
        $storyId = $input->getOption('by-id');

        // Validate story lookup options
        if ($storySlug && $storyId) {
            $output->writeln('<error>Provide only one of --by-slug or --by-id</error>');
            return self::FAILURE;
        }

        if (!$storySlug && !$storyId && !$input->getOption('no-interaction')) {
            $method = (string) select(
                label: 'How do you want to find the story?',
                options: [
                    'slug' => 'By slug',
                    'id' => 'By ID',
                ],
                default: 'slug',
            );
            $value = text(
                label: $method === 'slug' ? 'Enter the story slug' : 'Enter the story ID',
                placeholder: $method === 'slug' ? 'E.g. authors/john-doe' : 'E.g. 123456789',
                required: true,
            );
            match ($method) {
                'slug' => $storySlug = $value,
                'id' => $storyId = $value,
                default => null,
            };
        }

        if (!$storySlug && !$storyId) {
            $output->writeln('<error>Provide one of --by-slug or --by-id</error>');
            return self::FAILURE;
        }

        /** @var string|null $toFolderSlug */
        $toFolderSlug = $input->getOption('to-folder-slug');
        /** @var string|null $toFolderIdRaw */
        $toFolderIdRaw = $input->getOption('to-folder-id');

        // Validate folder options
        if ($toFolderSlug && $toFolderIdRaw !== null) {
            $output->writeln('<error>Provide only one of --to-folder-slug or --to-folder-id</error>');
            return self::FAILURE;
        }

        if (!$toFolderSlug && $toFolderIdRaw === null && !$input->getOption('no-interaction')) {
            $method = (string) select(
                label: 'How do you want to specify the target folder?',
                options: [
                    'slug' => 'By folder slug',
                    'id' => 'By folder ID',
                    'root' => 'Move to root (no folder)',
                ],
                default: 'slug',
            );
            match ($method) {
                'slug' => $toFolderSlug = text(
                    label: 'Enter the target folder slug',
                    placeholder: 'E.g. archived/authors',
                    required: true,
                ),
                'id' => $toFolderIdRaw = text(
                    label: 'Enter the target folder ID',
                    placeholder: 'E.g. 123456789',
                    required: true,
                ),
                'root' => $toFolderIdRaw = '0',
                default => null,
            };
        }

        if (!$toFolderSlug && $toFolderIdRaw === null) {
            $output->writeln('<error>Provide one of --to-folder-slug or --to-folder-id</error>');
            return self::FAILURE;
        }

        $action = new StoryMoveAction($this->client);

        try {
            // Resolve folder ID
            $folderId = $toFolderSlug ? $action->resolveFolderBySlug($this->spaceId, $toFolderSlug) : (int) $toFolderIdRaw;

            $result = $action->execute(
                spaceId: $this->spaceId,
                folderId: $folderId,
                storyId: $storyId,
                storySlug: $storySlug,
            );
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        Render::title('Story Moved');
        Render::labelValue('Story', $result->story->name());
        Render::labelValue('Slug', $result->story->slug());
        Render::labelValue('Full slug', $result->story->fullSlug());
        Render::labelValue('Previous full slug', $result->previousFullSlug);
        Render::labelValue('Previous folder ID', (string) $result->previousFolderId);
        Render::labelValue('New folder ID', (string) $result->newFolderId);

        return self::SUCCESS;
    }
}
