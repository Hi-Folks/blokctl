<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

final readonly class SpaceCollaboratorRoleSetResult
{
    /**
     * @param string $previousRole Current "role" value: admin, editor, or a custom role ID as string
     * @param int|null $previousSpaceRoleId Current custom space role ID, null when none
     * @param string $roleName Target role: admin, editor, or the custom role name
     * @param int|null $spaceRoleId Target custom space role ID, null for built-in roles
     * @param bool $changed False when the collaborator already has the target role
     */
    public function __construct(
        public int $collaboratorId,
        public string $email,
        public string $previousRole,
        public ?int $previousSpaceRoleId,
        public string $roleName,
        public ?int $spaceRoleId,
        public bool $changed,
    ) {}
}
