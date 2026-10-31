<?php

declare(strict_types=1);

namespace Blokctl\Action\Workflow;

use Blokctl\ApiError;
use Storyblok\ManagementApi\Endpoints\ManagementApi;
use Storyblok\ManagementApi\ManagementApiClient;

/**
 * Create a custom workflow, or update the content types of an existing one.
 *
 * The workflow is matched by exact name. A content type can belong to only one
 * workflow, so content types used by another workflow are rejected before any request.
 */
final readonly class WorkflowEnsureAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * @param list<string>|null $contentTypes Content types that use the workflow; null keeps the existing value.
     *                                        Required to create a workflow.
     *
     * @throws \RuntimeException if a content type belongs to another workflow or the API request fails
     */
    public function execute(string $spaceId, string $name, ?array $contentTypes = null): WorkflowEnsureResult
    {
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('Workflow name cannot be empty.');
        }

        $lookup = new WorkflowLookup($this->client);
        $workflows = $lookup->workflows($spaceId);
        $existing = null;
        foreach ($workflows as $workflow) {
            if (($workflow['name'] ?? null) === $name) {
                $existing = $workflow;
            }
        }

        if ($contentTypes !== null) {
            $contentTypes = array_values(array_unique(array_filter(array_map(trim(...), $contentTypes), static fn(string $type): bool => $type !== '')));
            $this->assertContentTypesAvailable($workflows, $existing, $contentTypes);
        }

        if ($existing === null) {
            if ($contentTypes === null || $contentTypes === []) {
                throw new \RuntimeException(sprintf('Workflow "%s" does not exist. At least one content type is required to create it.', $name));
            }

            $response = new ManagementApi($this->client)->makeRequest('POST', sprintf('/v1/spaces/%s/workflows', $spaceId), [
                'json' => ['workflow' => ['name' => $name, 'content_types' => $contentTypes]],
            ]);
            $workflow = $response->isOk() ? ($response->toArray()['workflow'] ?? null) : null;
            if (!is_array($workflow)) {
                throw new \RuntimeException(sprintf('Failed to create workflow "%s": %s', $name, ApiError::message($response)));
            }

            /** @var array<string, mixed> $workflow */
            return new WorkflowEnsureResult(WorkflowEnsureResult::CREATED, $workflow, ['content_types']);
        }

        if ($contentTypes === null || $this->sorted($existing['content_types'] ?? []) === $this->sorted($contentTypes)) {
            return new WorkflowEnsureResult(WorkflowEnsureResult::UNCHANGED, $existing, []);
        }

        $id = $this->intValue($existing['id'] ?? null);
        $response = new ManagementApi($this->client)->makeRequest('PUT', sprintf('/v1/spaces/%s/workflows/%d', $spaceId, $id), [
            'json' => ['workflow' => ['content_types' => $contentTypes]],
        ]);
        if (!$response->isOk()) {
            throw new \RuntimeException(sprintf('Failed to update workflow "%s": %s', $name, ApiError::message($response)));
        }

        // Updates return 204 without a body: read the workflow back.
        $workflow = $lookup->one(sprintf('spaces/%s/workflows/%d', $spaceId, $id), 'workflow');

        return new WorkflowEnsureResult(WorkflowEnsureResult::UPDATED, $workflow, ['content_types']);
    }

    /**
     * @param list<array<string, mixed>> $workflows
     * @param array<string, mixed>|null $existing
     * @param list<string> $contentTypes
     */
    private function assertContentTypesAvailable(array $workflows, ?array $existing, array $contentTypes): void
    {
        foreach ($workflows as $workflow) {
            if ($existing !== null && ($workflow['id'] ?? null) === ($existing['id'] ?? null)) {
                continue;
            }

            $used = array_intersect($contentTypes, $this->sorted($workflow['content_types'] ?? []));
            if ($used !== []) {
                throw new \RuntimeException(sprintf(
                    'Content type "%s" already belongs to workflow "%s". A content type can belong to only one workflow.',
                    reset($used),
                    is_string($workflow['name'] ?? null) ? $workflow['name'] : '',
                ));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function sorted(mixed $values): array
    {
        $items = is_array($values) ? array_values(array_filter(array_map(static fn(mixed $value): string => is_scalar($value) ? (string) $value : '', $values), static fn(string $value): bool => $value !== '')) : [];
        sort($items);

        return $items;
    }

    private function intValue(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new \RuntimeException('Workflow has no numeric ID.');
        }

        return (int) $value;
    }
}
