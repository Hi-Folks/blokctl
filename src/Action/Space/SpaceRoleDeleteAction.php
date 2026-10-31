<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

use Blokctl\ApiError;
use Storyblok\ManagementApi\Endpoints\ManagementApi;
use Storyblok\ManagementApi\ManagementApiClient;

/**
 * Delete a custom space role.
 *
 * The Management API docs don't state what happens to collaborators that still
 * have the role, so preflight() lists them to let callers decide first.
 */
final readonly class SpaceRoleDeleteAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * Find the role and the collaborators assigned to it, without changing the space.
     *
     * @param string $role Custom space role name or ID
     *
     * @throws \RuntimeException if the role is not found or the API request fails
     */
    public function preflight(string $spaceId, string $role): SpaceRoleDeleteResult
    {
        $spaceRole = new SpaceRoleListAction($this->client)->execute($spaceId)->findByNameOrId($role);
        $id = $spaceRole['id'] ?? null;
        $name = $spaceRole['role'] ?? null;
        if (!is_numeric($id) || !is_string($name)) {
            throw new \RuntimeException('Space role not found: ' . $role);
        }

        $assigned = [];
        foreach (new SpaceCollaboratorListAction($this->client)->execute($spaceId)->collaborators as $collaborator) {
            $roleIds = is_array($collaborator['space_role_ids'] ?? null) ? $collaborator['space_role_ids'] : [];
            $roleIds[] = $collaborator['space_role_id'] ?? null;
            if (in_array((string) $id, array_map(static fn(mixed $value): string => is_scalar($value) ? (string) $value : '', $roleIds), true)) {
                $assigned[] = SpaceCollaboratorListResult::emailOf($collaborator);
            }
        }

        return new SpaceRoleDeleteResult((int) $id, $name, $assigned);
    }

    /**
     * @throws \RuntimeException if the API request fails
     */
    public function execute(string $spaceId, SpaceRoleDeleteResult $preflight): void
    {
        $response = new ManagementApi($this->client)->delete(
            sprintf('spaces/%s/space_roles/%d', $spaceId, $preflight->roleId),
        );

        if (!$response->isOk()) {
            throw new \RuntimeException(
                sprintf('Failed to delete space role "%s": %s', $preflight->roleName, ApiError::message($response)),
            );
        }
    }
}
