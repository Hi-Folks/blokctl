<?php

declare(strict_types=1);

namespace Blokctl\Action\Workflow;

use Blokctl\ApiError;
use Storyblok\ManagementApi\Endpoints\ManagementApi;
use Storyblok\ManagementApi\ManagementApiClient;

/**
 * Read workflows and workflow stages as plain arrays, as returned by the Management API.
 */
final readonly class WorkflowLookup
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * @return list<array<string, mixed>>
     *
     * @throws \RuntimeException if the workflows cannot be fetched
     */
    public function workflows(string $spaceId): array
    {
        return $this->items(sprintf('spaces/%s/workflows', $spaceId), [], 'workflows');
    }

    /**
     * Find a workflow by exact name or numeric ID.
     *
     * @param list<array<string, mixed>> $workflows
     * @return array<string, mixed>|null
     */
    public static function find(array $workflows, string $nameOrId): ?array
    {
        $nameOrId = trim($nameOrId);
        foreach ($workflows as $workflow) {
            $id = $workflow['id'] ?? null;
            if (($workflow['name'] ?? null) === $nameOrId || (is_scalar($id) && (string) $id === $nameOrId)) {
                return $workflow;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws \RuntimeException if the stages cannot be fetched
     */
    public function stages(string $spaceId, int $workflowId): array
    {
        return $this->items(sprintf('spaces/%s/workflow_stages', $spaceId), ['in_workflow' => $workflowId], 'workflow_stages');
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \RuntimeException if the resource cannot be fetched
     */
    public function one(string $path, string $key): array
    {
        $response = new ManagementApi($this->client)->get($path);
        $item = $response->isOk() ? ($response->toArray()[$key] ?? null) : null;
        if (!is_array($item)) {
            throw new \RuntimeException(sprintf('Failed to fetch %s: %s', $path, ApiError::message($response)));
        }

        /** @var array<string, mixed> $item */
        return $item;
    }

    /**
     * @param array<string, mixed> $query
     * @return list<array<string, mixed>>
     */
    private function items(string $path, array $query, string $key): array
    {
        $response = new ManagementApi($this->client)->get($path, $query);
        if (!$response->isOk()) {
            throw new \RuntimeException(sprintf('Failed to fetch %s: %s', str_replace('_', ' ', $key), ApiError::message($response)));
        }

        $data = $response->toArray();
        $items = [];
        foreach (is_array($data[$key] ?? null) ? $data[$key] : [] as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $items[] = $item;
            }
        }

        return $items;
    }
}
