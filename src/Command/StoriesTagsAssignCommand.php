<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Story\StoriesTagsAssignAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'stories:tags-assign',
    description: 'Assign tags to stories by their IDs or slugs',
)]
class StoriesTagsAssignCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->addOption(
            'story-id',
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Story ID to tag (repeatable)',
        );
        $this->addOption(
            'story-slug',
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Story slug to tag (repeatable)',
        );
        $this->addOption(
            'tag',
            't',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Tag name to assign (repeatable)',
        );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string[] $storyIds */
        $storyIds = $input->getOption('story-id');
        /** @var string[] $storySlugs */
        $storySlugs = $input->getOption('story-slug');
        /** @var string[] $tags */
        $tags = $input->getOption('tag');

        if ($storyIds === [] && $storySlugs === []) {
            Render::error('At least one --story-id or --story-slug is required.');
            return self::FAILURE;
        }

        if ($tags === []) {
            Render::error('At least one --tag is required.');
            return self::FAILURE;
        }

        Render::titleSection('Assigning tags: ' . implode(', ', $tags));

        $result = (new StoriesTagsAssignAction($this->client))->execute(
            spaceId: $this->spaceId,
            storyIds: $storyIds,
            storySlugs: $storySlugs,
            tags: $tags,
        );

        foreach ($result->errors as $error) {
            Render::error($error);
        }

        foreach ($result->tagged as $entry) {
            Render::log(
                'Story "' . $entry['name'] . '" tagged with ' . $entry['tags'],
            );
        }

        return self::SUCCESS;
    }
}
