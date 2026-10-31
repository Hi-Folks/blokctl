<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Workflow\WorkflowStageEnsureAction;
use Blokctl\Action\Workflow\WorkflowStageEnsureResult;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'workflow:stage-ensure',
    description: 'Create a workflow stage, or update its declared properties',
)]
class WorkflowStageEnsureCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('workflow', 'w', InputOption::VALUE_REQUIRED, 'Workflow name or ID')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Stage name (matched by exact name inside the workflow)')
            ->addOption('color', null, InputOption::VALUE_REQUIRED, 'Stage color, e.g. #ffcc12 (unique inside the workflow)')
            ->addOption('position', null, InputOption::VALUE_REQUIRED, 'Stage position in the workflow (1 = first)')
            ->addOption('default', null, InputOption::VALUE_NEGATABLE, 'Make this the default stage of the workflow')
            ->addOption('allow-publish', null, InputOption::VALUE_NEGATABLE, 'Allow publishing in this stage')
            ->addOption('allow-admin-publish', null, InputOption::VALUE_NEGATABLE, 'Allow admins to publish in this stage')
            ->addOption('allow-admin-change', null, InputOption::VALUE_NEGATABLE, 'Allow admins to move stories to the next stages')
            ->addOption('allow-editor-change', null, InputOption::VALUE_NEGATABLE, 'Allow editors to move stories to the next stages')
            ->addOption('next', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Next stage name or ID (repeatable); "*" for all stages')
            ->addOption('role', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Custom role name or ID that can move stories on (repeatable); "*" for all users')
            ->addOption('after-publish', null, InputOption::VALUE_REQUIRED, 'Stage name or ID assigned after publishing');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $workflow */
        $workflow = $input->getOption('workflow');
        /** @var string|null $name */
        $name = $input->getOption('name');
        if (empty($workflow) || empty($name)) {
            Render::error('Workflow and stage name are required. Provide them with --workflow and --name.');
            return self::FAILURE;
        }

        /** @var string|null $position */
        $position = $input->getOption('position');
        if ($position !== null && !ctype_digit($position)) {
            Render::error('--position must be a positive number.');
            return self::FAILURE;
        }

        /** @var string|null $color */
        $color = $input->getOption('color');
        /** @var string|null $afterPublish */
        $afterPublish = $input->getOption('after-publish');

        try {
            $result = new WorkflowStageEnsureAction($this->client)->execute(
                spaceId: $this->spaceId,
                workflow: $workflow,
                name: $name,
                color: $color,
                position: $position === null ? null : (int) $position,
                isDefault: $this->boolOption($input, 'default'),
                allowPublish: $this->boolOption($input, 'allow-publish'),
                allowAdminPublish: $this->boolOption($input, 'allow-admin-publish'),
                allowAdminChange: $this->boolOption($input, 'allow-admin-change'),
                allowEditorChange: $this->boolOption($input, 'allow-editor-change'),
                nextStages: $this->listOption($input, 'next'),
                roles: $this->listOption($input, 'role'),
                afterPublish: $afterPublish,
            );
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        Render::title(match ($result->status) {
            WorkflowStageEnsureResult::CREATED => 'Workflow Stage Created',
            WorkflowStageEnsureResult::UPDATED => 'Workflow Stage Updated',
            default => 'Workflow Stage Already Matches',
        });
        Render::labelValue('Space ID', $this->spaceId);
        Render::labelValue('Workflow', htmlspecialchars($workflow, ENT_QUOTES));
        Render::labelValue('Stage', htmlspecialchars($name, ENT_QUOTES));
        Render::labelValue('Stage ID', (string) $result->stageId());
        if ($result->changedFields !== []) {
            Render::labelValue('Changed', implode(', ', $result->changedFields));
        }

        return self::SUCCESS;
    }

    private function boolOption(InputInterface $input, string $name): ?bool
    {
        $value = $input->getOption($name);

        return is_bool($value) ? $value : null;
    }

    /**
     * A repeatable option as a list, or null when it was not provided (not declared).
     *
     * @return list<string>|null
     */
    private function listOption(InputInterface $input, string $name): ?array
    {
        /** @var list<string> $values */
        $values = $input->getOption($name);

        return $values === [] ? null : $values;
    }
}
