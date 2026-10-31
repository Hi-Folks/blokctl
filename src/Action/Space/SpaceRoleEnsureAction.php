<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

use Blokctl\Action\Story\StoryResolveIdAction;
use Blokctl\ApiError;
use Storyblok\ManagementApi\Endpoints\ManagementApi;
use Storyblok\ManagementApi\ManagementApiClient;

/**
 * Create a custom space role, or update the declared properties of an existing one.
 *
 * Every property argument is optional: null means "not declared" and the existing
 * value is kept. A declared list replaces the existing list (order is ignored).
 */
final readonly class SpaceRoleEnsureAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * @param list<string>|null $permissions Permission keys, e.g. read_stories, save_stories, view_content
     * @param list<string|int>|null $allowedPaths Stories or folders the role can access, by full slug or ID (Elite plan)
     * @param list<string|int>|null $blockedPaths Stories or folders the role cannot access, by full slug or ID
     * @param list<string>|null $allowedLanguages Language codes the role can edit, e.g. default, de
     *
     * @throws \RuntimeException if a path cannot be resolved or the API request fails
     */
    public function execute(
        string $spaceId,
        string $name,
        ?string $subtitle = null,
        ?array $permissions = null,
        ?array $allowedPaths = null,
        ?array $blockedPaths = null,
        ?array $allowedLanguages = null,
    ): SpaceRoleEnsureResult {
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('Space role name cannot be empty.');
        }

        $resolver = new StoryResolveIdAction($this->client);
        $declared = array_filter([
            'subtitle' => $subtitle,
            'permissions' => $permissions === null ? null : $this->uniqueStrings($permissions),
            'allowed_paths' => $allowedPaths === null ? null : $resolver->executeMany($spaceId, $allowedPaths),
            'blocked_paths' => $blockedPaths === null ? null : $resolver->executeMany($spaceId, $blockedPaths),
            'allowed_languages' => $allowedLanguages === null ? null : $this->uniqueStrings($allowedLanguages),
        ], static fn(mixed $value): bool => $value !== null);

        $existing = new SpaceRoleListAction($this->client)
            ->execute($spaceId, $name)
            ->findByName($name);

        if ($existing === null) {
            $role = $this->send('POST', sprintf('/v1/spaces/%s/space_roles', $spaceId), ['role' => $name, ...$declared]);

            return new SpaceRoleEnsureResult(SpaceRoleEnsureResult::CREATED, $role, array_keys($declared));
        }

        $changes = [];
        foreach ($declared as $key => $value) {
            if ($this->normalize($existing[$key] ?? null) !== $this->normalize($value)) {
                $changes[$key] = $value;
            }
        }

        if ($changes === []) {
            return new SpaceRoleEnsureResult(SpaceRoleEnsureResult::UNCHANGED, $existing, []);
        }

        $id = $existing['id'] ?? null;
        if (!is_numeric($id)) {
            throw new \RuntimeException('Space role "' . $name . '" has no numeric ID.');
        }

        $role = $this->send('PUT', sprintf('/v1/spaces/%s/space_roles/%s', $spaceId, $id), ['role' => $name, ...$changes]);

        return new SpaceRoleEnsureResult(SpaceRoleEnsureResult::UPDATED, $role, array_keys($changes));
    }

    /**
     * Send a space role payload as JSON and return the space role from the response.
     *
     * @param array<string, mixed> $spaceRole
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $spaceRole): array
    {
        $response = new ManagementApi($this->client)->makeRequest($method, $path, [
            'json' => ['space_role' => $spaceRole],
        ]);

        if (!$response->isOk()) {
            throw new \RuntimeException(
                sprintf('Failed to save space role "%s": %s', $this->stringValue($spaceRole['role'] ?? ''), ApiError::message($response)),
            );
        }

        $role = $response->toArray()['space_role'] ?? null;
        if (!is_array($role)) {
            throw new \RuntimeException('The space role response did not include space_role.');
        }

        /** @var array<string, mixed> $role */
        return $role;
    }

    /**
     * Comparable form of a role property: lists become sorted lists of strings.
     */
    private function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value === null ? null : $this->stringValue($value);
        }

        $items = array_map($this->stringValue(...), array_values($value));
        sort($items);

        return $items;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function uniqueStrings(array $values): array
    {
        $values = array_filter(array_map(trim(...), $values), static fn(string $value): bool => $value !== '');

        return array_values(array_unique($values));
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
