<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

use Storyblok\ManagementApi\Endpoints\ManagementApi;
use Storyblok\ManagementApi\ManagementApiClient;

/**
 * Change the role of an existing collaborator.
 *
 * Sets the collaborator's main role (role + space_role_id). Additional roles in
 * space_role_ids are not changed.
 */
final readonly class SpaceCollaboratorRoleSetAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * Find the collaborator and the target role, without changing the space.
     *
     * @param string $collaborator Collaborator email or numeric collaborator ID
     * @param string $role "admin", "editor", or a custom space role name or ID
     *
     * @throws \RuntimeException if the collaborator or role is not found, or the API request fails
     */
    public function preflight(string $spaceId, string $collaborator, string $role): SpaceCollaboratorRoleSetResult
    {
        $collaborators = new SpaceCollaboratorListAction($this->client)->execute($spaceId);
        $collaborator = trim($collaborator);
        $found = ctype_digit($collaborator)
            ? $this->findById($collaborators, $collaborator)
            : $collaborators->findByEmail($collaborator);

        $id = $found['id'] ?? null;
        if ($found === null || !is_numeric($id)) {
            throw new \RuntimeException('Collaborator not found: ' . $collaborator . ' (pending invitations are not listed)');
        }

        [$roleName, $spaceRoleId] = new SpaceRoleResolveAction($this->client)->execute($spaceId, $role);
        $currentRoleId = is_numeric($found['space_role_id'] ?? null) && (int) $found['space_role_id'] > 0
            ? (int) $found['space_role_id']
            : null;
        $currentRole = is_scalar($found['role'] ?? null) ? (string) $found['role'] : '';

        $unchanged = $spaceRoleId === null
            ? $currentRoleId === null && $currentRole === $roleName
            : $currentRoleId === $spaceRoleId;

        return new SpaceCollaboratorRoleSetResult(
            collaboratorId: (int) $id,
            email: SpaceCollaboratorListResult::emailOf($found),
            previousRole: $currentRole,
            previousSpaceRoleId: $currentRoleId,
            roleName: $roleName,
            spaceRoleId: $spaceRoleId,
            changed: !$unchanged,
        );
    }

    /**
     * Apply the role from the preflight. Does nothing when the role already matches.
     *
     * @throws \RuntimeException if the API request fails
     */
    public function execute(string $spaceId, SpaceCollaboratorRoleSetResult $preflight): void
    {
        if (!$preflight->changed) {
            return;
        }

        $payload = $preflight->spaceRoleId === null
            ? ['role' => $preflight->roleName, 'space_role_id' => null]
            : ['role' => (string) $preflight->spaceRoleId, 'space_role_id' => $preflight->spaceRoleId];

        $response = new ManagementApi($this->client)->makeRequest(
            'PUT',
            sprintf('/v1/spaces/%s/collaborators/%d', $spaceId, $preflight->collaboratorId),
            ['json' => ['collaborator' => $payload]],
        );

        if (!$response->isOk()) {
            throw new \RuntimeException(
                sprintf('Failed to set the role of %s: %s', $preflight->email, $response->getErrorMessage()),
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findById(SpaceCollaboratorListResult $collaborators, string $id): ?array
    {
        foreach ($collaborators->collaborators as $collaborator) {
            $collaboratorId = $collaborator['id'] ?? null;
            if (is_scalar($collaboratorId) && (string) $collaboratorId === $id) {
                return $collaborator;
            }
        }

        return null;
    }
}
