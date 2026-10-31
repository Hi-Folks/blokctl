<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Space\SpaceCollaboratorRoleSetAction;
use Blokctl\Action\Space\SpaceRoleListAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\confirm;

#[AsCommand(
    name: 'space:collaborator-role-set',
    description: 'Change the role of an existing collaborator',
)]
class SpaceCollaboratorRoleSetCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('email', 'e', InputOption::VALUE_REQUIRED, 'Collaborator email')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Collaborator ID')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Role: admin, editor, or a custom space role name or ID')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the role change without applying it');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $email */
        $email = $input->getOption('email');
        /** @var string|null $id */
        $id = $input->getOption('id');
        /** @var string|null $role */
        $role = $input->getOption('role');

        if (empty($email) === empty($id)) {
            Render::error('Provide exactly one of --email or --id.');
            return self::FAILURE;
        }

        if (empty($role)) {
            Render::error('Role is required. Provide it with --role.');
            return self::FAILURE;
        }

        $action = new SpaceCollaboratorRoleSetAction($this->client);
        try {
            $preflight = $action->preflight($this->spaceId, (string) ($email ?: $id), $role);
            $roles = new SpaceRoleListAction($this->client)->execute($this->spaceId);
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        $current = $preflight->previousSpaceRoleId === null
            ? $preflight->previousRole
            : $this->stringValue($roles->findByNameOrId((string) $preflight->previousSpaceRoleId)['role'] ?? '?') . ' (' . $preflight->previousSpaceRoleId . ')';
        $target = $preflight->spaceRoleId === null
            ? $preflight->roleName
            : $preflight->roleName . ' (' . $preflight->spaceRoleId . ')';

        Render::title('Collaborator Role');
        Render::labelValue('Space ID', $this->spaceId);
        Render::labelValue('Collaborator', htmlspecialchars($preflight->email . ' (' . $preflight->collaboratorId . ')', ENT_QUOTES));
        Render::labelValue('Current role', htmlspecialchars($current, ENT_QUOTES));
        Render::labelValue('New role', htmlspecialchars($target, ENT_QUOTES));

        if (!$preflight->changed) {
            Render::log('The collaborator already has this role.');
            return self::SUCCESS;
        }

        if ($input->getOption('dry-run')) {
            Render::log('Dry run: no changes made.');
            return self::SUCCESS;
        }

        $confirmed = (bool) $input->getOption('no-interaction') || confirm(
            label: sprintf('Change the role of %s?', $preflight->email),
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

        Render::log('Role changed.');

        return self::SUCCESS;
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
