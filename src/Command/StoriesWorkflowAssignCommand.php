<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Story\StoriesWorkflowAssignAction;
use Blokctl\Render;
use Storyblok\ManagementApi\Data\Story;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

#[AsCommand(
    name: 'stories:workflow-assign',
    description: 'Assign workflow stages to stories that have none',
)]
class StoriesWorkflowAssignCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->addOption(
            'workflow-stage-id',
            null,
            InputOption::VALUE_REQUIRED,
            'Workflow stage ID to assign (prompted interactively if omitted)',
        );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $action = new StoriesWorkflowAssignAction($this->client);
        $result = $action->preflight($this->spaceId);

        Render::titleSection('Stories: handling workflows');

        /** @var Story $story */
        foreach ($result->stories as $story) {
            /** @var string $storyName */
            $storyName = $story->get('name');
            /** @var string $stageId */
            $stageId = $story->get('stage.workflow_stage_id');
            Render::labelValueCondition(
                $storyName,
                $story->hasWorkflowStage(),
                'Has Stage: ' . $stageId,
                'No Workflow Stage',
            );
        }

        Render::titleSection(
            'There are ' . $result->countWithoutStage . ' stories with no workflow stage.',
        );

        if ($result->countWithoutStage === 0) {
            Render::log('All stories already have workflow stages');
            return self::SUCCESS;
        }

        $noInteraction = $input->getOption('no-interaction');

        if (!$noInteraction) {
            $wantFixWorkflow = confirm(
                label: 'Do you want to fix workflow stages for not assigned Stories?',
                default: true,
                yes: 'Yes, pick empty stories and apply workflow stage',
                no: 'No, please skip',
                hint: 'Apply a Drafting stage to stories with no stage',
            );
            if (!$wantFixWorkflow) {
                Render::log('Skipping Fix workflow stages');
                return self::SUCCESS;
            }
        }

        // Resolve workflow stage ID
        /** @var string|null $workflowStageId */
        $workflowStageId = $input->getOption('workflow-stage-id');

        if (empty($workflowStageId)) {
            $options = $result->workflowStages;
            $options[0] = 'Skip, no changes';

            $workflowStageId = select(
                label: 'Which workflow stage do you want to apply?',
                options: $options,
                default: $result->defaultStageId,
            );
        }

        if ((int) $workflowStageId === 0) {
            Render::log('Skipping workflow stage assignment');
            return self::SUCCESS;
        }

        Render::log('Applying workflow stage ' . $workflowStageId);

        $executeResult = $action->execute($this->spaceId, $result, (int) $workflowStageId);

        foreach ($executeResult['errors'] as $error) {
            Render::error($error);
        }

        foreach ($executeResult['assigned'] as $entry) {
            Render::log(
                'Workflow Stage Change (' . $entry['stageId'] .
                ') applied to story ' . $entry['name'],
            );
        }

        return self::SUCCESS;
    }
}
