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

    /**
     * Find a role by its exact name or numeric ID.
     *
     * @return array<string, mixed>|null
     */
    public function findByNameOrId(string $nameOrId): ?array
    {
        $nameOrId = trim($nameOrId);
        foreach ($this->roles as $role) {
            $id = $role['id'] ?? null;
            if (($role['role'] ?? null) === $nameOrId || (is_scalar($id) && (string) $id === $nameOrId)) {
                return $role;
            }
        }

        return null;
    }
}
