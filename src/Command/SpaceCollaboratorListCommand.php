<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Space\SpaceCollaboratorListAction;
use Blokctl\Action\Space\SpaceCollaboratorListResult;
use Blokctl\Action\Space\SpaceRoleListAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'space:collaborator-list',
    description: 'List the collaborators of the space with their roles',
)]
class SpaceCollaboratorListCommand extends AbstractCommand
{
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        try {
            $result = new SpaceCollaboratorListAction($this->client)->execute($this->spaceId);
            $roles = new SpaceRoleListAction($this->client)->execute($this->spaceId);
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        if ($result->count() === 0) {
            Render::log('No collaborators found (the space owner and pending invitations are not listed)');
            return self::SUCCESS;
        }

        $roleNames = [];
        foreach ($roles->roles as $role) {
            $roleNames[$this->stringValue($role['id'] ?? '')] = $this->stringValue($role['role'] ?? '');
        }

        Render::title('Collaborators (' . $result->count() . ')');
        foreach ($result->collaborators as $collaborator) {
            $user = is_array($collaborator['user'] ?? null) ? $collaborator['user'] : [];
            $roleId = $this->stringValue($collaborator['space_role_id'] ?? '');
            $role = $roleId !== '' && isset($roleNames[$roleId])
                ? $roleNames[$roleId] . ' (' . $roleId . ')'
                : $this->stringValue($collaborator['role'] ?? '');

            Render::titleSection($this->escape(SpaceCollaboratorListResult::emailOf($collaborator)));
            Render::labelValue('Name', $this->escape($user['friendly_name'] ?? ''));
            Render::labelValue('Role', $this->escape($role));
            Render::labelValue('Collaborator ID', $this->escape($collaborator['id'] ?? ''));
            Render::labelValueCondition('Pending invitation', SpaceCollaboratorListResult::isPending($collaborator));
        }

        return self::SUCCESS;
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function escape(mixed $value): string
    {
        return htmlspecialchars($this->stringValue($value), ENT_QUOTES);
    }
}
