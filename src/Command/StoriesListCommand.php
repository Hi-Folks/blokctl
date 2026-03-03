<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Story\StoriesListAction;
use Blokctl\Render;
use Storyblok\ManagementApi\Data\Story;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'stories:list',
    description: 'List stories with optional filters',
)]
class StoriesListCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption(
                'content-type',
                'c',
                InputOption::VALUE_REQUIRED,
                'Filter by content type (component name)',
            )
            ->addOption(
                'starts-with',
                's',
                InputOption::VALUE_REQUIRED,
                'Filter by slug prefix (e.g. "blog/")',
            )
            ->addOption(
                'search',
                null,
                InputOption::VALUE_REQUIRED,
                'Search stories by name',
            )
            ->addOption(
                'with-tag',
                't',
                InputOption::VALUE_REQUIRED,
                'Filter by tag (comma-separated for multiple)',
            )
            ->addOption(
                'published-only',
                null,
                InputOption::VALUE_NONE,
                'Only show published stories',
            )
            ->addOption(
                'page',
                'p',
                InputOption::VALUE_REQUIRED,
                'Page number',
                '1',
            )
            ->addOption(
                'per-page',
                null,
                InputOption::VALUE_REQUIRED,
                'Results per page (max 100)',
                '25',
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $contentType */
        $contentType = $input->getOption('content-type');
        /** @var string|null $startsWith */
        $startsWith = $input->getOption('starts-with');
        /** @var string|null $search */
        $search = $input->getOption('search');
        /** @var string|null $withTag */
        $withTag = $input->getOption('with-tag');
        /** @var string $pageOption */
        $pageOption = $input->getOption('page');
        /** @var string $perPageOption */
        $perPageOption = $input->getOption('per-page');

        $result = (new StoriesListAction($this->client))->execute(
            spaceId: $this->spaceId,
            contentType: $contentType,
            startsWith: $startsWith,
            search: $search,
            withTag: $withTag,
            publishedOnly: (bool) $input->getOption('published-only'),
            page: (int) $pageOption,
            perPage: (int) $perPageOption,
        );

        if ($result->count() === 0) {
            Render::log('No stories found matching the filters');
            return self::SUCCESS;
        }

        Render::titleSection(
            'Stories (page ' . $pageOption .
            ', showing ' . $result->count() . ')',
        );

        /** @var Story $story */
        foreach ($result->stories as $story) {
            $details = [];
            /** @var string $fullSlug */
            $fullSlug = $story->get('full_slug');
            $details[] = 'slug: ' . $fullSlug;
            if ($story->hasTags()) {
                $details[] = 'tags: ' . $story->tagListAsString();
            }

            if ($story->hasWorkflowStage()) {
                /** @var string $stageId */
                $stageId = $story->get('stage.workflow_stage_id');
                $details[] = 'stage: ' . $stageId;
            }

            /** @var string $storyName */
            $storyName = $story->get('name');
            Render::labelValue(
                $storyName,
                implode(' | ', $details),
            );
        }

        return self::SUCCESS;
    }
}
