<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

final readonly class SpaceRoleDeleteResult
{
    /**
     * @param list<string> $assignedEmails Emails of collaborators that have the role
     */
    public function __construct(
        public int $roleId,
        public string $roleName,
        public array $assignedEmails,
    ) {}

    public function isAssigned(): bool
    {
        return $this->assignedEmails !== [];
    }
}
