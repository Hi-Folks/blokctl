<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

use Blokctl\ApiError;
use Storyblok\ManagementApi\Endpoints\ManagementApi;
use Storyblok\ManagementApi\ManagementApiClient;

final readonly class SpaceRoleListAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * List the custom space roles, optionally filtered by role name.
     *
     * @throws \RuntimeException if the roles cannot be fetched
     */
    public function execute(string $spaceId, ?string $search = null): SpaceRoleListResult
    {
        $query = $search === null || $search === '' ? [] : ['search' => $search];
        $response = new ManagementApi($this->client)->get(
            sprintf('spaces/%s/space_roles', $spaceId),
            $query,
        );

        if (!$response->isOk()) {
            throw new \RuntimeException('Failed to fetch space roles: ' . ApiError::message($response));
        }

        $data = $response->toArray();
        $roles = [];
        $rawRoles = is_array($data['space_roles'] ?? null) ? $data['space_roles'] : [];
        foreach ($rawRoles as $role) {
            if (is_array($role) && is_string($role['role'] ?? null)) {
                /** @var array<string, mixed> $role */
                $roles[] = $role;
            }
        }

        return new SpaceRoleListResult(roles: $roles);
    }
}
