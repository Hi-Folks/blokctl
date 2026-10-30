<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

use Storyblok\ManagementApi\Endpoints\ManagementApi;
use Storyblok\ManagementApi\ManagementApiClient;

/**
 * Add users to a space by email, skipping current collaborators.
 *
 * A user without a Storyblok account gets a pending invitation (the API returns
 * "user": null and an "invitation" object that expires after 7 days). Pending
 * invitations are not returned by the collaborators list, so they cannot be
 * detected and skipped on a later run.
 */
final readonly class SpaceCollaboratorInviteAction
{
    private const array BUILT_IN_ROLES = ['admin', 'editor'];

    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * Resolve the role and split the emails into new and existing collaborators,
     * without changing the space.
     *
     * @param list<string> $emails
     * @param string $role "admin", "editor", or a custom space role name or ID
     *
     * @throws \RuntimeException if an email is invalid, the role is unknown, or the API request fails
     */
    public function preflight(string $spaceId, array $emails, string $role = 'editor'): SpaceCollaboratorInviteResult
    {
        $normalized = [];
        foreach ($emails as $email) {
            $email = strtolower(trim($email));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('Invalid email address: ' . $email);
            }

            $normalized[$email] = $email;
        }

        if ($normalized === []) {
            throw new \RuntimeException('At least one email address is required.');
        }

        [$roleName, $spaceRoleId] = $this->resolveRole($spaceId, $role);
        $existing = new SpaceCollaboratorListAction($this->client)->execute($spaceId);

        $entries = [];
        foreach ($normalized as $email) {
            $entries[] = [
                'email' => $email,
                'status' => $existing->findByEmail($email) === null
                    ? SpaceCollaboratorInviteResult::PLANNED
                    : SpaceCollaboratorInviteResult::SKIPPED,
                'message' => '',
            ];
        }

        return new SpaceCollaboratorInviteResult($roleName, $spaceRoleId, $entries);
    }

    /**
     * Add every planned email from the preflight. Failures are collected per email.
     */
    public function execute(string $spaceId, SpaceCollaboratorInviteResult $preflight): SpaceCollaboratorInviteResult
    {
        $api = new ManagementApi($this->client);
        $payload = $preflight->spaceRoleId === null
            ? ['role' => $preflight->roleName]
            : ['role' => (string) $preflight->spaceRoleId, 'space_role_id' => $preflight->spaceRoleId];

        $entries = [];
        foreach ($preflight->entries as $entry) {
            if ($entry['status'] !== SpaceCollaboratorInviteResult::PLANNED) {
                $entries[] = $entry;
                continue;
            }

            $response = $api->makeRequest('POST', sprintf('/v1/spaces/%s/collaborators/', $spaceId), [
                'json' => ['email' => $entry['email'], ...$payload],
            ]);

            if (!$response->isOk()) {
                $entries[] = [...$entry, 'status' => SpaceCollaboratorInviteResult::FAILED, 'message' => $response->getErrorMessage()];
                continue;
            }

            $collaborator = $response->toArray()['collaborator'] ?? null;
            $entries[] = [
                ...$entry,
                'status' => is_array($collaborator) && SpaceCollaboratorListResult::isPending($collaborator)
                    ? SpaceCollaboratorInviteResult::INVITED
                    : SpaceCollaboratorInviteResult::ADDED,
            ];
        }

        return new SpaceCollaboratorInviteResult($preflight->roleName, $preflight->spaceRoleId, $entries);
    }

    /**
     * @return array{0: string, 1: int|null} Role name and custom space role ID (null for built-in roles)
     */
    private function resolveRole(string $spaceId, string $role): array
    {
        $role = trim($role);
        if (in_array(strtolower($role), self::BUILT_IN_ROLES, true)) {
            return [strtolower($role), null];
        }

        $roles = new SpaceRoleListAction($this->client)->execute($spaceId);
        foreach ($roles->roles as $spaceRole) {
            $id = $spaceRole['id'] ?? null;
            $name = $spaceRole['role'] ?? null;
            if (is_numeric($id) && is_string($name) && ($name === $role || (string) $id === $role)) {
                return [$name, (int) $id];
            }
        }

        throw new \RuntimeException('Space role not found: ' . $role);
    }
}
