<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

use Storyblok\ManagementApi\Data\WorkflowStageChange;
use Storyblok\ManagementApi\Endpoints\StoryApi;
use Storyblok\ManagementApi\Endpoints\WorkflowApi;
use Storyblok\ManagementApi\Endpoints\WorkflowStageApi;
use Storyblok\ManagementApi\Endpoints\WorkflowStageChangeApi;
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\QueryParameters\StoriesParams;

final readonly class StoryWorkflowChangeAction
{
    public function __construct(private ManagementApiClient $client) {}

    /**
     * Fetch workflow stages for interactive selection.
     *
     * @throws \RuntimeException if workflow or stages are not found
     */
    public function preflight(
        string $spaceId,
        ?string $workflowName = null,
        ?string $workflowId = null,
    ): StoryWorkflowChangePreflightResult {
        $workflowId = $this->resolveWorkflowId($spaceId, $workflowName, $workflowId);
        $workflowStages = $this->listWorkflowStages($spaceId, $workflowId);

        return new StoryWorkflowChangePreflightResult(
            workflowId: $workflowId,
            workflowStages: $workflowStages,
        );
    }

    /**
     * Change the workflow stage of a story.
     *
     * @throws \RuntimeException if story, workflow, or workflow stage is not found
     */
    public function execute(
        string $spaceId,
        ?string $storyId = null,
        ?string $storySlug = null,
        ?string $stageName = null,
        ?int $stageId = null,
        ?string $workflowName = null,
        ?string $workflowId = null,
    ): StoryWorkflowChangeResult {
        if ($storySlug !== null && $storyId !== null) {
            throw new \RuntimeException("Provide only one of story slug or ID.");
        }

        if ($stageName !== null && $stageId !== null) {
            throw new \RuntimeException("Provide only one of workflow stage name or ID.");
        }

        if ($workflowName !== null && $workflowId !== null) {
            throw new \RuntimeException("Provide only one of workflow name or ID.");
        }

        if ($stageName === null && $stageId === null) {
            throw new \RuntimeException("Provide either a workflow stage name or ID.");
        }

        if ($stageId === 0) {
            $resolvedStage = [
                'stageId' => 0,
                'stageName' => 'None',
            ];
        } else {
            $workflowId = $this->resolveWorkflowId($spaceId, $workflowName, $workflowId);
            $resolvedStage = $this->resolveWorkflowStage($spaceId, $workflowId, $stageName, $stageId);
        }

        $storyApi = new StoryApi($this->client, $spaceId);
        $storyId = $this->resolveStoryId($storyApi, $storySlug, $storyId);

        $response = $storyApi->get($storyId);
        if (!$response->isOk()) {
            throw new \RuntimeException("Story not found with ID: " . $storyId);
        }

        $storyData = $response->data();
        /** @var int|null $previousStageId */
        $previousStageId = $storyData->get('stage.workflow_stage_id') ?: null;

        $this->createWorkflowStageChange(
            spaceId: $spaceId,
            storyId: (int) $storyId,
            workflowStageId: $resolvedStage['stageId'],
        );

        return new StoryWorkflowChangeResult(
            story: $storyData,
            workflowStageName: $resolvedStage['stageName'],
            workflowStageId: $resolvedStage['stageId'],
            previousWorkflowStageId: $previousStageId,
        );
    }

    /**
     * Resolve the workflow ID from name, explicit ID, or default.
     */
    private function resolveWorkflowId(
        string $spaceId,
        ?string $workflowName,
        ?string $workflowId,
    ): string {
        $workflowApi = new WorkflowApi($this->client, $spaceId);
        $workflows = $workflowApi->list()->data();

        if ($workflowId !== null) {
            /** @phpstan-ignore foreach.nonIterable */
            foreach ($workflows as $workflow) {
                if ((string) $workflow->get('id') === $workflowId) {
                    return $workflowId;
                }
            }

            throw new \RuntimeException(
                "Workflow not found with ID: " . $workflowId,
            );
        }

        if ($workflowName !== null) {
            /** @phpstan-ignore foreach.nonIterable */
            foreach ($workflows as $workflow) {
                /** @var string $name */
                $name = $workflow->get('name');
                if (strcasecmp($name, $workflowName) === 0) {
                    return (string) $workflow->get('id');
                }
            }

            throw new \RuntimeException(
                "Workflow not found with name: " . $workflowName,
            );
        }

        $defaultWorkflow = null;
        /** @phpstan-ignore foreach.nonIterable */
        foreach ($workflows as $workflow) {
            if ($defaultWorkflow === null) {
                $defaultWorkflow = $workflow;
            }

            if ($workflow->getBoolean('is_default')) {
                $defaultWorkflow = $workflow;
            }
        }

        if ($defaultWorkflow === null) {
            throw new \RuntimeException("No workflows found.");
        }

        return (string) $defaultWorkflow->get('id');
    }

    /**
     * @return array{stageId: int, stageName: string}
     */
    private function resolveWorkflowStage(
        string $spaceId,
        string $workflowId,
        ?string $stageName,
        ?int $stageId,
    ): array {
        if ($stageName === null && $stageId === null) {
            throw new \RuntimeException("Provide either a workflow stage name or ID.");
        }

        $workflowStages = $this->listWorkflowStages($spaceId, $workflowId);

        if ($stageId !== null) {
            foreach ($workflowStages as $id => $name) {
                if ((string) $id === (string) $stageId) {
                    return [
                        'stageId' => (int) $id,
                        'stageName' => $name,
                    ];
                }
            }

            throw new \RuntimeException(
                "Workflow stage not found with ID: " . $stageId
                . ". Available stages: " . implode(', ', $workflowStages),
            );
        }

        foreach ($workflowStages as $id => $name) {
            if ($stageName !== null && strcasecmp($name, $stageName) === 0) {
                return [
                    'stageId' => (int) $id,
                    'stageName' => $name,
                ];
            }
        }

        throw new \RuntimeException(
            "Workflow stage not found with name: " . $stageName
            . ". Available stages: " . implode(', ', $workflowStages),
        );
    }

    /**
     * @return array<int|string, string>
     */
    private function listWorkflowStages(string $spaceId, string $workflowId): array
    {
        $stageApi = new WorkflowStageApi($this->client, $spaceId);
        $stages = $stageApi->list($workflowId)->data();

        $workflowStages = [];

        /** @phpstan-ignore foreach.nonIterable */
        foreach ($stages as $stage) {
            /** @var int|string $id */
            $id = $stage->get('id');
            /** @var string $name */
            $name = $stage->get('name');
            $workflowStages[$id] = $name;
        }

        if ($workflowStages === []) {
            throw new \RuntimeException("No workflow stages found.");
        }

        return $workflowStages;
    }

    /**
     * @throws \RuntimeException if the story is not found
     */
    private function resolveStoryId(
        StoryApi $storyApi,
        ?string $storySlug,
        ?string $storyId,
    ): string {
        if ($storySlug !== null && $storyId === null) {
            return $this->resolveStoryIdFromSlug($storyApi, $storySlug);
        }

        if ($storyId === null) {
            throw new \RuntimeException("Provide either a story ID or slug.");
        }

        return $storyId;
    }

    private function resolveStoryIdFromSlug(StoryApi $storyApi, string $storySlug): string
    {
        $params = new StoriesParams(withSlug: $storySlug);
        $stories = $storyApi->page($params)->data();
        $storyCount = count($stories);

        if ($storyCount === 0) {
            throw new \RuntimeException(
                "Story not found with slug: " . $storySlug,
            );
        }

        if ($storyCount > 1) {
            throw new \RuntimeException(
                "Multiple stories found with slug: " . $storySlug,
            );
        }

        /** @var array{id: int|string} $story */
        $story = $stories[0];

        return (string) $story["id"];
    }

    private function createWorkflowStageChange(
        string $spaceId,
        int $storyId,
        int $workflowStageId,
    ): void {
        $changesApi = new WorkflowStageChangeApi($this->client, $spaceId);
        $changesApi->create(
            WorkflowStageChange::makeFromParams(
                $storyId,
                $workflowStageId,
            ),
        );
    }
}
