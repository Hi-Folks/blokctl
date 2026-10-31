<?php

declare(strict_types=1);

namespace Blokctl\Action\Component;

use Blokctl\ApiError;
use Storyblok\ManagementApi\Endpoints\ManagementApi;
use Storyblok\ManagementApi\ManagementApiClient;

/**
 * Ensure a component folder path exists in the block library, for example
 * `Marketing/Banners`. Missing folders are created, nested under their parent.
 *
 * Folder names are unique in the whole space, not per parent, so a name that already
 * exists under a different parent is reported as an error instead of being created.
 */
final readonly class ComponentFolderEnsureAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * @throws \RuntimeException if the path is empty, a folder name is used under another parent, or the API request fails
     */
    public function execute(string $spaceId, string $path): ComponentFolderEnsureResult
    {
        $segments = array_values(array_filter(array_map(trim(...), explode('/', $path)), static fn(string $segment): bool => $segment !== ''));
        if ($segments === []) {
            throw new \RuntimeException('Component folder path cannot be empty.');
        }

        $folders = $this->folders($spaceId);
        $this->assertPathAvailable($folders, $segments);

        $parentId = null;
        $folder = [];
        $created = [];
        foreach ($segments as $index => $name) {
            $existing = $folders[$name] ?? null;
            if ($existing !== null) {
                $folder = $existing;
            } else {
                $payload = ['name' => $name];
                if ($parentId !== null) {
                    $payload['parent_id'] = $parentId;
                }

                $response = new ManagementApi($this->client)->makeRequest('POST', sprintf('/v1/spaces/%s/component_groups', $spaceId), [
                    'json' => ['component_group' => $payload],
                ]);
                $new = $response->isOk() ? ($response->toArray()['component_group'] ?? null) : null;
                if (!is_array($new)) {
                    throw new \RuntimeException(sprintf('Failed to create component folder "%s": %s', $name, ApiError::message($response)));
                }

                /** @var array<string, mixed> $new */
                $folder = $new;
                $folders[$name] = $new;
                $created[] = implode('/', array_slice($segments, 0, $index + 1));
            }

            $parentId = is_numeric($folder['id'] ?? null) ? (int) $folder['id'] : null;
        }

        return new ComponentFolderEnsureResult($folder, $created);
    }

    /**
     * Check the whole path before creating anything: an existing folder must sit under the
     * previous folder of the path, and a folder below a new one cannot exist yet.
     *
     * @param array<string, array<string, mixed>> $folders
     * @param list<string> $segments
     */
    private function assertPathAvailable(array $folders, array $segments): void
    {
        $parentId = null;
        $parentIsNew = false;
        foreach ($segments as $index => $name) {
            $existing = $folders[$name] ?? null;
            if ($existing === null) {
                $parentIsNew = true;
                continue;
            }

            $existingParent = is_numeric($existing['parent_id'] ?? null) ? (int) $existing['parent_id'] : null;
            if ($parentIsNew || $existingParent !== $parentId) {
                throw new \RuntimeException(sprintf(
                    'Component folder "%s" already exists in another location. Folder names are unique in the space.',
                    implode('/', array_slice($segments, 0, $index + 1)),
                ));
            }

            $parentId = is_numeric($existing['id'] ?? null) ? (int) $existing['id'] : null;
        }
    }

    /**
     * @return array<string, array<string, mixed>> Folder name => folder
     */
    private function folders(string $spaceId): array
    {
        $response = new ManagementApi($this->client)->get(sprintf('spaces/%s/component_groups', $spaceId));
        if (!$response->isOk()) {
            throw new \RuntimeException('Failed to fetch component folders: ' . ApiError::message($response));
        }

        $data = $response->toArray();
        $folders = [];
        foreach (is_array($data['component_groups'] ?? null) ? $data['component_groups'] : [] as $folder) {
            $name = is_array($folder) ? ($folder['name'] ?? null) : null;
            if (is_array($folder) && is_string($name)) {
                /** @var array<string, mixed> $folder */
                $folders[$name] = $folder;
            }
        }

        return $folders;
    }
}
