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
     * Resolve workflow stage ID by name.
     *
     * Looks up stages in the given workflow (or the default workflow if none specified).
     *
     * @return array{stageId: int, stageName: string, workflowStages: array<int|string, string>}
     *
     * @throws \RuntimeException if workflow or stage is not found
     */
    public function resolveWorkflowStage(
        string $spaceId,
        ?string $stageName = null,
        ?string $workflowName = null,
        ?string $workflowId = null,
    ): array {
        $resolvedWorkflowId = $this->resolveWorkflowId($spaceId, $workflowName, $workflowId);

        $stageApi = new WorkflowStageApi($this->client, $spaceId);
        $stages = $stageApi->list($resolvedWorkflowId)->data();

        /** @var array<int|string, string> $workflowStages */
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

        if ($stageName !== null) {
            foreach ($workflowStages as $id => $name) {
                if (strcasecmp($name, $stageName) === 0) {
                    return [
                        'stageId' => (int) $id,
                        'stageName' => $name,
                        'workflowStages' => $workflowStages,
                    ];
                }
            }

            throw new \RuntimeException(
                "Workflow stage not found with name: " . $stageName
                . ". Available stages: " . implode(', ', $workflowStages),
            );
        }

        return [
            'stageId' => 0,
            'stageName' => '',
            'workflowStages' => $workflowStages,
        ];
    }

    /**
     * Change the workflow stage of a story.
     *
     * @throws \RuntimeException if story or workflow stage is not found
     */
    public function execute(
        string $spaceId,
        int $workflowStageId,
        string $workflowStageName,
        ?string $storyId = null,
        ?string $storySlug = null,
    ): StoryWorkflowChangeResult {
        $storyApi = new StoryApi($this->client, $spaceId);

        // Resolve story ID from slug if needed
        if ($storySlug !== null && $storyId === null) {
            $params = new StoriesParams(withSlug: $storySlug);
            $stories = $storyApi->page($params)->data();

            if (count($stories) !== 1) {
                throw new \RuntimeException(
                    "Story not found with slug: " . $storySlug,
                );
            }

            /** @var array{id: int|string} $story */
            $story = $stories[0];
            $storyId = (string) $story["id"];
        }

        if ($storyId === null) {
            throw new \RuntimeException("Provide either a story ID or slug.");
        }

        // Fetch the story to get current state
        $response = $storyApi->get($storyId);
        if (!$response->isOk()) {
            throw new \RuntimeException("Story not found with ID: " . $storyId);
        }

        $storyData = $response->data();
        /** @var int|null $previousStageId */
        $previousStageId = $storyData->get('stage.workflow_stage_id') ?: null;

        // Apply workflow stage change
        $changesApi = new WorkflowStageChangeApi($this->client, $spaceId);
        $changesApi->create(
            WorkflowStageChange::makeFromParams(
                (int) $storyId,
                $workflowStageId,
            ),
        );

        return new StoryWorkflowChangeResult(
            story: $storyData,
            workflowStageName: $workflowStageName,
            workflowStageId: $workflowStageId,
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
        if ($workflowId !== null) {
            return $workflowId;
        }

        $workflowApi = new WorkflowApi($this->client, $spaceId);
        $workflows = $workflowApi->list()->data();

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

        // Use the default workflow
        $defaultWorkflow = $workflows->get('0');
        /** @phpstan-ignore foreach.nonIterable */
        foreach ($workflows as $workflow) {
            if ($workflow->getBoolean('is_default')) {
                $defaultWorkflow = $workflow;
            }
        }

        return (string) $defaultWorkflow->get('id');
    }
}
