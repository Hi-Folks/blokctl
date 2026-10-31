<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

use Storyblok\ManagementApi\ManagementApiClient;

/**
 * Resolve a collaborator role reference: a built-in role (admin, editor) or a
 * custom space role by name or ID.
 */
final readonly class SpaceRoleResolveAction
{
    private const array BUILT_IN_ROLES = ['admin', 'editor'];

    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * @param string $role "admin", "editor", or a custom space role name or ID
     * @return array{0: string, 1: int|null} Role name and custom space role ID (null for built-in roles)
     *
     * @throws \RuntimeException if the custom role is not found or the API request fails
     */
    public function execute(string $spaceId, string $role): array
    {
        $role = trim($role);
        if (in_array(strtolower($role), self::BUILT_IN_ROLES, true)) {
            return [strtolower($role), null];
        }

        $spaceRole = new SpaceRoleListAction($this->client)->execute($spaceId)->findByNameOrId($role);
        $id = $spaceRole['id'] ?? null;
        $name = $spaceRole['role'] ?? null;
        if (!is_numeric($id) || !is_string($name)) {
            throw new \RuntimeException('Space role not found: ' . $role);
        }

        return [$name, (int) $id];
    }
}
