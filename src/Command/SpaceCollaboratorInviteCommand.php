<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Space\SpaceCollaboratorInviteAction;
use Blokctl\Action\Space\SpaceCollaboratorInviteResult;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\confirm;

#[AsCommand(
    name: 'space:collaborator-invite',
    description: 'Add users to the space by email with a role (current collaborators are skipped)',
)]
class SpaceCollaboratorInviteCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('email', 'e', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Email address to add (repeatable)')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Role: admin, editor, or a custom space role name or ID', 'editor')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show who would be added without changing the space');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var list<string> $emails */
        $emails = $input->getOption('email');
        /** @var string $role */
        $role = $input->getOption('role');
        $noInteraction = (bool) $input->getOption('no-interaction');

        $action = new SpaceCollaboratorInviteAction($this->client);
        try {
            $preflight = $action->preflight($this->spaceId, $emails, $role);
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        Render::title('Collaborator Invite');
        Render::labelValue('Space ID', $this->spaceId);
        Render::labelValue('Role', htmlspecialchars($this->roleLabel($preflight), ENT_QUOTES));
        $this->renderEntries($preflight);

        $planned = $preflight->emailsWithStatus(SpaceCollaboratorInviteResult::PLANNED);
        if ($planned === []) {
            Render::log('Nothing to do: every email is already a collaborator.');
            return self::SUCCESS;
        }

        if ($input->getOption('dry-run')) {
            Render::log('Dry run: no changes made.');
            return self::SUCCESS;
        }

        $confirmed = $noInteraction || confirm(
            label: sprintf('Add %d user(s) to the space? Users without a Storyblok account receive an invitation.', count($planned)),
            default: false,
        );
        if (!$confirmed) {
            Render::log('Cancelled.');
            return self::SUCCESS;
        }

        $result = $action->execute($this->spaceId, $preflight);
        Render::titleSection('Result');
        $this->renderEntries($result);

        return $result->hasFailures() ? self::FAILURE : self::SUCCESS;
    }

    private function renderEntries(SpaceCollaboratorInviteResult $result): void
    {
        foreach ($result->entries as $entry) {
            $status = match ($entry['status']) {
                SpaceCollaboratorInviteResult::PLANNED => 'will be added',
                SpaceCollaboratorInviteResult::SKIPPED => 'already a collaborator, skipped',
                SpaceCollaboratorInviteResult::ADDED => 'added (existing Storyblok user)',
                SpaceCollaboratorInviteResult::INVITED => 'invitation pending (no Storyblok account yet)',
                default => 'failed: ' . $entry['message'],
            };
            Render::labelValue(htmlspecialchars($entry['email'], ENT_QUOTES), htmlspecialchars($status, ENT_QUOTES));
        }
    }

    private function roleLabel(SpaceCollaboratorInviteResult $result): string
    {
        return $result->spaceRoleId === null
            ? $result->roleName
            : $result->roleName . ' (' . $result->spaceRoleId . ')';
    }
}
