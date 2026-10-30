<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Space\SpaceCollaboratorRemoveAction;
use Blokctl\Action\Space\SpaceCollaboratorRemoveResult;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\confirm;

#[AsCommand(
    name: 'space:collaborator-remove',
    description: 'Remove collaborators from the space by email or collaborator ID',
)]
class SpaceCollaboratorRemoveCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('email', 'e', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Email of the collaborator to remove (repeatable)')
            ->addOption('id', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Collaborator ID to remove, also for pending invitations (repeatable)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show who would be removed without changing the space');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var list<string> $emails */
        $emails = $input->getOption('email');
        /** @var list<string> $ids */
        $ids = $input->getOption('id');

        $action = new SpaceCollaboratorRemoveAction($this->client);
        try {
            $preflight = $action->preflight($this->spaceId, $emails, $ids);
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        Render::title('Collaborator Remove');
        Render::labelValue('Space ID', $this->spaceId);
        $this->renderEntries($preflight);

        $planned = $preflight->entriesWithStatus(SpaceCollaboratorRemoveResult::PLANNED);
        if ($planned === []) {
            Render::log('Nothing to remove.');
            return $preflight->entriesWithStatus(SpaceCollaboratorRemoveResult::NOT_FOUND) === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($input->getOption('dry-run')) {
            Render::log('Dry run: no changes made.');
            return self::SUCCESS;
        }

        $confirmed = (bool) $input->getOption('no-interaction') || confirm(
            label: sprintf('Remove %d collaborator(s) from the space?', count($planned)),
            default: false,
        );
        if (!$confirmed) {
            Render::log('Cancelled.');
            return self::SUCCESS;
        }

        $result = $action->execute($this->spaceId, $preflight);
        Render::titleSection('Result');
        $this->renderEntries($result);

        return $result->hasFailures() || $result->entriesWithStatus(SpaceCollaboratorRemoveResult::NOT_FOUND) !== []
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function renderEntries(SpaceCollaboratorRemoveResult $result): void
    {
        foreach ($result->entries as $entry) {
            $label = $entry['email'] !== '' ? $entry['email'] : 'ID ' . $entry['collaboratorId'];
            $status = match ($entry['status']) {
                SpaceCollaboratorRemoveResult::PLANNED => $entry['email'] === ''
                    ? 'will be removed (not listed, e.g. pending invitation)'
                    : 'will be removed (ID ' . $entry['collaboratorId'] . ')',
                SpaceCollaboratorRemoveResult::NOT_FOUND => 'not a collaborator',
                SpaceCollaboratorRemoveResult::REMOVED => 'removed',
                default => 'failed: ' . $entry['message'],
            };
            Render::labelValue(htmlspecialchars($label, ENT_QUOTES), htmlspecialchars($status, ENT_QUOTES));
        }
    }
}
