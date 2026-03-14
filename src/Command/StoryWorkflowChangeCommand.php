<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Story\StoryWorkflowChangeAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[AsCommand(
    name: 'story:workflow-change',
    description: 'Change the workflow stage of a story',
)]
class StoryWorkflowChangeCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('by-slug', null, InputOption::VALUE_REQUIRED, 'Find story by full slug')
            ->addOption('by-id', null, InputOption::VALUE_REQUIRED, 'Find story by numeric ID')
            ->addOption('stage', null, InputOption::VALUE_REQUIRED, 'Workflow stage name to assign')
            ->addOption('stage-id', null, InputOption::VALUE_REQUIRED, 'Workflow stage ID to assign')
            ->addOption('workflow-name', null, InputOption::VALUE_REQUIRED, 'Workflow name (uses default workflow if omitted)')
            ->addOption('workflow-id', null, InputOption::VALUE_REQUIRED, 'Workflow ID (uses default workflow if omitted)')
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
                placeholder: $method === 'slug' ? 'E.g. articles/my-article' : 'E.g. 123456789',
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

        /** @var string|null $stageName */
        $stageName = $input->getOption('stage');
        /** @var string|null $stageIdRaw */
        $stageIdRaw = $input->getOption('stage-id');
        /** @var string|null $workflowName */
        $workflowName = $input->getOption('workflow-name');
        /** @var string|null $workflowId */
        $workflowId = $input->getOption('workflow-id');

        if ($stageName && $stageIdRaw) {
            $output->writeln('<error>Provide only one of --stage or --stage-id</error>');
            return self::FAILURE;
        }

        if ($workflowName && $workflowId) {
            $output->writeln('<error>Provide only one of --workflow-name or --workflow-id</error>');
            return self::FAILURE;
        }

        $action = new StoryWorkflowChangeAction($this->client);

        try {
            // If stage ID is provided directly, resolve stage name from available stages
            if ($stageIdRaw !== null) {
                $resolved = $action->resolveWorkflowStage(
                    $this->spaceId,
                    null,
                    $workflowName,
                    $workflowId,
                );
                $stageId = (int) $stageIdRaw;
                $resolvedStageName = $resolved['workflowStages'][$stageId] ?? 'Unknown';
            } else {
                // Resolve workflow stage by name or interactively
                $resolved = $action->resolveWorkflowStage(
                    $this->spaceId,
                    $stageName,
                    $workflowName,
                    $workflowId,
                );

                $stageId = $resolved['stageId'];
                $resolvedStageName = $resolved['stageName'];

                // If neither --stage nor --stage-id was provided, prompt interactively
                if ($stageName === null) {
                    if ($input->getOption('no-interaction')) {
                        $output->writeln('<error>Provide --stage or --stage-id when using --no-interaction</error>');
                        return self::FAILURE;
                    }

                    /** @var array<int|string, string> $options */
                    $options = $resolved['workflowStages'];
                    $stageId = (int) select(
                        label: 'Which workflow stage do you want to assign?',
                        options: $options,
                    );
                    $resolvedStageName = $options[$stageId];
                }
            }

            $result = $action->execute(
                spaceId: $this->spaceId,
                workflowStageId: $stageId,
                workflowStageName: $resolvedStageName,
                storyId: $storyId,
                storySlug: $storySlug,
            );
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        Render::title('Workflow Stage Changed');
        Render::labelValue('Story', $result->story->name());
        Render::labelValue('Story slug', $result->story->slug());
        Render::labelValue('Workflow stage', $result->workflowStageName . ' (' . $result->workflowStageId . ')');
        if ($result->previousWorkflowStageId !== null) {
            Render::labelValue('Previous stage ID', (string) $result->previousWorkflowStageId);
        } else {
            Render::labelValue('Previous stage', 'None');
        }

        return self::SUCCESS;
    }
}
