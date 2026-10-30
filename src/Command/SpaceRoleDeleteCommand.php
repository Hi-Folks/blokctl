<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Space\SpaceRoleDeleteAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\confirm;

#[AsCommand(
    name: 'space:role-delete',
    description: 'Delete a custom space role',
)]
class SpaceRoleDeleteCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Custom space role name or ID')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the role and its collaborators without deleting it');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $role */
        $role = $input->getOption('role');
        if (empty($role)) {
            Render::error('Role is required. Provide it with --role (name or ID).');
            return self::FAILURE;
        }

        $action = new SpaceRoleDeleteAction($this->client);
        try {
            $preflight = $action->preflight($this->spaceId, $role);
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        Render::title('Space Role Delete');
        Render::labelValue('Space ID', $this->spaceId);
        Render::labelValue('Role', htmlspecialchars($preflight->roleName, ENT_QUOTES));
        Render::labelValue('Role ID', (string) $preflight->roleId);
        Render::labelValue('Assigned collaborators', $preflight->isAssigned()
            ? htmlspecialchars(implode(', ', $preflight->assignedEmails), ENT_QUOTES)
            : 'none');
        if ($preflight->isAssigned()) {
            Render::log('These collaborators still have the role. Pending invitations are not listed.');
        }

        if ($input->getOption('dry-run')) {
            Render::log('Dry run: no changes made.');
            return self::SUCCESS;
        }

        $confirmed = (bool) $input->getOption('no-interaction') || confirm(
            label: sprintf('Delete the space role "%s"?', $preflight->roleName),
            default: false,
        );
        if (!$confirmed) {
            Render::log('Cancelled.');
            return self::SUCCESS;
        }

        try {
            $action->execute($this->spaceId, $preflight);
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        Render::log('Space role deleted.');

        return self::SUCCESS;
    }
}
