<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Workflow\WorkflowStageShowAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[AsCommand(
    name: 'workflow:stage-show',
    description: 'Show details of a workflow stage by name or ID',
)]
class WorkflowStageShowCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('by-name', null, InputOption::VALUE_REQUIRED, 'Find stage by name (case-insensitive)')
            ->addOption('by-id', null, InputOption::VALUE_REQUIRED, 'Find stage by numeric ID')
            ->addOption('workflow-name', null, InputOption::VALUE_REQUIRED, 'Workflow name (uses default workflow if omitted)')
            ->addOption('workflow-id', null, InputOption::VALUE_REQUIRED, 'Workflow numeric ID (uses default workflow if omitted)');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $stageName */
        $stageName = $input->getOption('by-name');
        /** @var string|null $stageIdRaw */
        $stageIdRaw = $input->getOption('by-id');
        /** @var string|null $workflowName */
        $workflowName = $input->getOption('workflow-name');
        /** @var string|null $workflowId */
        $workflowId = $input->getOption('workflow-id');

        if ($workflowName && $workflowId) {
            $output->writeln('<error>Provide only one of --workflow-name or --workflow-id</error>');
            return self::FAILURE;
        }

        if ($stageName && $stageIdRaw) {
            $output->writeln('<error>Provide only one of --by-name or --by-id</error>');
            return self::FAILURE;
        }

        if (!$stageName && !$stageIdRaw && !$input->getOption('no-interaction')) {
            $method = (string) select(
                label: 'How do you want to find the workflow stage?',
                options: [
                    'name' => 'By name',
                    'id' => 'By ID',
                ],
                default: 'name',
            );
            $value = text(
                label: $method === 'name' ? 'Enter the stage name' : 'Enter the stage ID',
                placeholder: $method === 'name' ? 'E.g. Drafting, Review' : 'E.g. 653554',
                required: true,
            );
            match ($method) {
                'name' => $stageName = $value,
                'id' => $stageIdRaw = $value,
                default => null,
            };
        }

        if (!$stageName && !$stageIdRaw) {
            $output->writeln('<error>Provide one of --by-name or --by-id</error>');
            return self::FAILURE;
        }

        try {
            $result = (new WorkflowStageShowAction($this->client))->execute(
                spaceId: $this->spaceId,
                stageId: $stageIdRaw !== null ? (int) $stageIdRaw : null,
                stageName: $stageName,
                workflowId: $workflowId,
                workflowName: $workflowName,
            );
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        $stage = $result->stage;

        /** @var string $stageName */
        $stageName = $stage['name'] ?? '';
        /** @var int|string $stageDisplayId */
        $stageDisplayId = $stage['id'] ?? '';
        /** @var int $position */
        $position = $stage['position'] ?? 0;
        /** @var string $color */
        $color = $stage['color'] ?? '';

        Render::title('Workflow Stage');
        Render::labelValue('Name', $stageName);
        Render::labelValue('ID', (string) $stageDisplayId);
        Render::labelValue('Workflow', $result->workflowName . ' (' . $result->workflowId . ')');
        Render::labelValue('Position', (string) $position);
        Render::labelValue('Color', $color);
        Render::labelValueCondition('Allow publish', (bool) ($stage['allow_publish'] ?? false));
        Render::labelValueCondition('Allow admin publish', (bool) ($stage['allow_admin_publish'] ?? false));
        Render::labelValueCondition('Allow all users', (bool) ($stage['allow_all_users'] ?? false));
        Render::labelValueCondition('Story editing locked', (bool) ($stage['story_editing_locked'] ?? false));

        if (!empty($stage['workflow_stage_ids'])) {
            /** @var array<int, int|string> $nextStageIds */
            $nextStageIds = $stage['workflow_stage_ids'];
            Render::labelValue(
                'Allowed next stages',
                implode(', ', array_map(strval(...), $nextStageIds)),
            );
        }

        return self::SUCCESS;
    }
}
