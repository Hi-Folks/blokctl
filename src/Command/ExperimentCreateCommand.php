<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Experiment\ExperimentCreateAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'experiment:create',
    description: 'Create a draft experiment',
)]
class ExperimentCreateCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption(
                'story-id',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Story ID to include in the experiment (optional, repeatable)',
            )
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Experiment API name')
            ->addOption('display-name', null, InputOption::VALUE_REQUIRED, 'Experiment display name')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'Experiment description');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string[] $storyIdOptions */
        $storyIdOptions = $input->getOption('story-id');
        /** @var string|null $name */
        $name = $input->getOption('name');
        /** @var string|null $displayName */
        $displayName = $input->getOption('display-name');
        /** @var string|null $description */
        $description = $input->getOption('description');

        $defaultSuffix = date('YmdHis');
        $name ??= 'blokctl_post_probe_' . $defaultSuffix;
        $displayName ??= 'blokctl POST probe ' . $defaultSuffix;
        $description ??= 'Temporary experiment created by blokctl to test POST permissions.';

        $storyIds = [];
        foreach ($storyIdOptions as $storyIdOption) {
            if (!ctype_digit($storyIdOption)) {
                Render::error('Story ID must be numeric: ' . $storyIdOption);
                return self::FAILURE;
            }

            $storyIds[] = (int) $storyIdOption;
        }

        try {
            $result = new ExperimentCreateAction($this->client)->execute(
                spaceId: $this->spaceId,
                name: $name,
                displayName: $displayName,
                description: $description,
                storyIds: $storyIds,
            );
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        Render::title('Experiment Created');
        Render::labelValue('Name', $result->experiment->name());
        Render::labelValue('Display name', $result->experiment->displayName());
        Render::labelValue('ID', $result->experiment->id());
        Render::labelValue('Status', $result->experiment->status());
        Render::labelValue('Story IDs', $storyIdOptions === [] ? 'None' : implode(', ', $storyIdOptions));

        return self::SUCCESS;
    }
}
