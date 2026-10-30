<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

final readonly class SpaceRoleListResult
{
    /**
     * @param list<array<string, mixed>> $roles Space role objects as returned by the Management API
     */
    public function __construct(
        public array $roles,
    ) {}

    public function count(): int
    {
        return count($this->roles);
    }

    /**
     * Find a role by its exact name.
     *
     * @return array<string, mixed>|null
     */
    public function findByName(string $name): ?array
    {
        foreach ($this->roles as $role) {
            if (($role['role'] ?? null) === $name) {
                return $role;
            }
        }

        return null;
    }
}
