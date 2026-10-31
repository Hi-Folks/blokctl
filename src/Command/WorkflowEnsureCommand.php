<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Workflow\WorkflowEnsureAction;
use Blokctl\Action\Workflow\WorkflowEnsureResult;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'workflow:ensure',
    description: 'Create a custom workflow, or update its content types',
)]
class WorkflowEnsureCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Workflow name (the workflow is matched by exact name)')
            ->addOption('content-type', 'c', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Content type that uses the workflow (repeatable; required to create)');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $name */
        $name = $input->getOption('name');
        if (empty($name)) {
            Render::error('Workflow name is required. Provide it with --name.');
            return self::FAILURE;
        }

        /** @var list<string> $contentTypes */
        $contentTypes = $input->getOption('content-type');

        try {
            $result = new WorkflowEnsureAction($this->client)->execute(
                spaceId: $this->spaceId,
                name: $name,
                contentTypes: $contentTypes === [] ? null : $contentTypes,
            );
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        Render::title(match ($result->status) {
            WorkflowEnsureResult::CREATED => 'Workflow Created',
            WorkflowEnsureResult::UPDATED => 'Workflow Updated',
            default => 'Workflow Already Matches',
        });
        Render::labelValue('Space ID', $this->spaceId);
        Render::labelValue('Workflow', htmlspecialchars($name, ENT_QUOTES));
        Render::labelValue('Workflow ID', (string) $result->workflowId());
        $types = is_array($result->workflow['content_types'] ?? null) ? $result->workflow['content_types'] : [];
        Render::labelValue('Content types', htmlspecialchars(implode(', ', array_map(static fn(mixed $type): string => is_scalar($type) ? (string) $type : '', $types)), ENT_QUOTES));
        if ($result->status === WorkflowEnsureResult::CREATED) {
            Render::log('A new workflow has no stages. Add them with workflow:stage-ensure.');
        }

        return self::SUCCESS;
    }
}
