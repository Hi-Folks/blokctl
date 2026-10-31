<?php

declare(strict_types=1);

namespace Blokctl\Action\Workflow;

use Blokctl\Action\Space\SpaceRoleListAction;
use Blokctl\ApiError;
use Storyblok\ManagementApi\Endpoints\ManagementApi;
use Storyblok\ManagementApi\ManagementApiClient;

/**
 * Create a workflow stage, or update the declared properties of an existing one.
 *
 * Every property argument is optional: null means "not declared" and the existing
 * value is kept. Stages are matched by exact name inside the workflow.
 *
 * Storyblok behavior handled here:
 * - A stage created without allow_all_users/allow_all_stages gets both set to true, so
 *   declaring roles or next stages also sends the matching flag as false.
 * - Only one stage per workflow can be the default: the current default is unset first.
 * - Updates return 204 without a body, so the stage is read back after updating.
 */
final readonly class WorkflowStageEnsureAction
{
    /** Use as the only item of $nextStages or $roles to allow all stages or all users. */
    public const string ALL = '*';

    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * @param string $workflow Workflow name or ID
     * @param list<string|int>|null $nextStages Stage names or IDs a story can move to, [ALL] for all stages, [] for none
     * @param list<string|int>|null $roles Custom space role names or IDs that can move a story on, [ALL] for all users, [] for none
     * @param string|int|null $afterPublish Stage name or ID assigned after publishing
     *
     * @throws \RuntimeException if the workflow, a stage, or a role cannot be resolved, or the API request fails
     */
    public function execute(
        string $spaceId,
        string $workflow,
        string $name,
        ?string $color = null,
        ?int $position = null,
        ?bool $isDefault = null,
        ?bool $allowPublish = null,
        ?bool $allowAdminPublish = null,
        ?bool $allowAdminChange = null,
        ?bool $allowEditorChange = null,
        ?array $nextStages = null,
        ?array $roles = null,
        string|int|null $afterPublish = null,
    ): WorkflowStageEnsureResult {
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('Workflow stage name cannot be empty.');
        }

        $lookup = new WorkflowLookup($this->client);
        $workflowData = WorkflowLookup::find($lookup->workflows($spaceId), $workflow);
        $workflowId = is_numeric($workflowData['id'] ?? null) ? (int) $workflowData['id'] : null;
        if ($workflowId === null) {
            throw new \RuntimeException('Workflow not found: ' . $workflow);
        }

        $stages = $lookup->stages($spaceId, $workflowId);
        $existing = $this->findStage($stages, $name);

        $declared = array_filter([
            'color' => $color,
            'position' => $position,
            'is_default' => $isDefault,
            'allow_publish' => $allowPublish,
            'allow_admin_publish' => $allowAdminPublish,
            'allow_admin_change' => $allowAdminChange,
            'allow_editor_change' => $allowEditorChange,
            'after_publish_id' => $afterPublish === null ? null : $this->stageId($stages, (string) $afterPublish, $workflow),
        ], static fn(mixed $value): bool => $value !== null);

        if ($nextStages !== null) {
            $all = $nextStages === [self::ALL];
            $declared['allow_all_stages'] = $all;
            $declared['workflow_stage_ids'] = $all ? [] : array_values(array_unique(array_map(
                fn(string|int $stage): int => $this->stageId($stages, (string) $stage, $workflow),
                $nextStages,
            )));
        }

        if ($roles !== null) {
            $all = $roles === [self::ALL];
            $declared['allow_all_users'] = $all;
            $declared['space_role_ids'] = $all ? [] : $this->roleIds($spaceId, $roles);
        }

        if ($isDefault === true) {
            $this->unsetOtherDefault($spaceId, $stages, $existing);
        }

        if ($existing === null) {
            $response = new ManagementApi($this->client)->makeRequest('POST', sprintf('/v1/spaces/%s/workflow_stages', $spaceId), [
                'json' => ['workflow_stage' => ['workflow_id' => $workflowId, 'name' => $name, ...$declared]],
            ]);
            $stage = $response->isOk() ? ($response->toArray()['workflow_stage'] ?? null) : null;
            if (!is_array($stage)) {
                throw new \RuntimeException(sprintf('Failed to create workflow stage "%s": %s', $name, ApiError::message($response)));
            }

            /** @var array<string, mixed> $stage */
            return new WorkflowStageEnsureResult(WorkflowStageEnsureResult::CREATED, $stage, array_keys($declared));
        }

        $changes = [];
        foreach ($declared as $key => $value) {
            if ($this->normalize($existing[$key] ?? null) !== $this->normalize($value)) {
                $changes[$key] = $value;
            }
        }

        if ($changes === []) {
            return new WorkflowStageEnsureResult(WorkflowStageEnsureResult::UNCHANGED, $existing, []);
        }

        $stageId = $this->intValue($existing['id'] ?? null);
        $this->update($spaceId, $stageId, $changes, $name);

        return new WorkflowStageEnsureResult(
            WorkflowStageEnsureResult::UPDATED,
            $lookup->one(sprintf('spaces/%s/workflow_stages/%d', $spaceId, $stageId), 'workflow_stage'),
            array_keys($changes),
        );
    }

    /**
     * @param list<array<string, mixed>> $stages
     * @param array<string, mixed>|null $existing
     */
    private function unsetOtherDefault(string $spaceId, array $stages, ?array $existing): void
    {
        foreach ($stages as $stage) {
            if (($stage['is_default'] ?? false) === true && ($existing === null || ($stage['id'] ?? null) !== ($existing['id'] ?? null))) {
                $this->update($spaceId, $this->intValue($stage['id'] ?? null), ['is_default' => false], $this->stringValue($stage['name'] ?? ''));
            }
        }
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function update(string $spaceId, int $stageId, array $changes, string $name): void
    {
        $response = new ManagementApi($this->client)->makeRequest('PUT', sprintf('/v1/spaces/%s/workflow_stages/%d', $spaceId, $stageId), [
            'json' => ['workflow_stage' => $changes],
        ]);

        if (!$response->isOk()) {
            throw new \RuntimeException(sprintf('Failed to update workflow stage "%s": %s', $name, ApiError::message($response)));
        }
    }

    /**
     * @param list<array<string, mixed>> $stages
     * @return array<string, mixed>|null
     */
    private function findStage(array $stages, string $nameOrId): ?array
    {
        $nameOrId = trim($nameOrId);
        foreach ($stages as $stage) {
            $id = $stage['id'] ?? null;
            if (($stage['name'] ?? null) === $nameOrId || (is_scalar($id) && (string) $id === $nameOrId)) {
                return $stage;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $stages
     */
    private function stageId(array $stages, string $nameOrId, string $workflow): int
    {
        $stage = $this->findStage($stages, $nameOrId);
        if ($stage === null) {
            throw new \RuntimeException(sprintf('Workflow stage "%s" not found in workflow "%s".', $nameOrId, $workflow));
        }

        return $this->intValue($stage['id'] ?? null);
    }

    /**
     * @param list<string|int> $roles
     * @return list<int>
     */
    private function roleIds(string $spaceId, array $roles): array
    {
        if ($roles === []) {
            return [];
        }

        $spaceRoles = new SpaceRoleListAction($this->client)->execute($spaceId);
        $ids = [];
        foreach ($roles as $role) {
            $id = $spaceRoles->findByNameOrId((string) $role)['id'] ?? null;
            if (!is_numeric($id)) {
                throw new \RuntimeException('Space role not found: ' . $role);
            }

            $ids[] = (int) $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Comparable form of a stage property: booleans and scalars as strings, lists sorted.
     */
    private function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            $items = array_map($this->stringValue(...), array_values($value));
            sort($items);

            return $items;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return $value === null ? null : $this->stringValue($value);
    }

    private function intValue(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new \RuntimeException('Workflow stage has no numeric ID.');
        }

        return (int) $value;
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
