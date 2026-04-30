<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

use Storyblok\ManagementApi\Data\Stories;
use Storyblok\ManagementApi\Data\Story;
use Storyblok\ManagementApi\Data\WorkflowStageChange;
use Storyblok\ManagementApi\Endpoints\StoryApi;
use Storyblok\ManagementApi\Endpoints\WorkflowApi;
use Storyblok\ManagementApi\Endpoints\WorkflowStageApi;
use Storyblok\ManagementApi\Endpoints\WorkflowStageChangeApi;
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\QueryParameters\PaginationParams;
use Storyblok\ManagementApi\QueryParameters\StoriesParams;

final readonly class StoriesWorkflowAssignAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * Fetch stories and available workflow stages.
     */
    public function preflight(string $spaceId, int $perPage = 25): StoriesWorkflowAssignResult
    {
        $storyApi = new StoryApi($this->client, $spaceId);
        $params = new StoriesParams(storyOnly: true);

        /** @var array<int, array<string, mixed>> $allStories */
        $allStories = [];
        $page = 1;

        do {
            $response = $storyApi->page(
                $params,
                page: new PaginationParams(page: $page, perPage: $perPage),
            );
            $pageData = $response->data();
            $fetchedCount = $pageData->count();

            foreach ($pageData->toArray() as $story) {
                $allStories[] = $story;
            }

            ++$page;
        } while ($fetchedCount >= $perPage);

        $stories = Stories::make($allStories);

        $countWithoutStage = 0;
        /** @var Story $story */
        foreach ($stories as $story) {
            if (!$story->hasWorkflowStage()) {
                ++$countWithoutStage;
            }
        }

        // Resolve workflow stages for interactive selection
        /** @var array<string, string> $workflowStages */
        $workflowStages = [];
        $defaultStageId = null;

        if ($countWithoutStage > 0) {
            $workflowApi = new WorkflowApi($this->client, $spaceId);
            $workflows = $workflowApi->list()->data();
            $defaultWorkflow = $workflows->get('0');
            /** @phpstan-ignore foreach.nonIterable */
            foreach ($workflows as $workflow) {
                if ($workflow->getBoolean('is_default')) {
                    $defaultWorkflow = $workflow;
                }
            }

            $workflowId = $defaultWorkflow->get('id');

            $workflowStageApi = new WorkflowStageApi($this->client, $spaceId);
            $stages = $workflowStageApi->list($workflowId)->data();

            /** @phpstan-ignore foreach.nonIterable */
            foreach ($stages as $stage) {
                /** @var string $stageId */
                $stageId = $stage->get('id');
                /** @var string $stageName */
                $stageName = $stage->get('name');
                $workflowStages[$stageId] = $stageName;
            }

            /** @var int|string $rawStageId */
            $rawStageId = $stages->get('0.id');
            $defaultStageId = (string) $rawStageId;
        }

        return new StoriesWorkflowAssignResult(
            stories: $stories,
            countWithoutStage: $countWithoutStage,
            defaultStageId: $defaultStageId,
            workflowStages: $workflowStages,
        );
    }

    /**
     * Assign a workflow stage to all stories without one.
     *
     * @return array{assigned: array<array{name: string, stageId: int|null}>, errors: string[]}
     */
    public function execute(
        string $spaceId,
        StoriesWorkflowAssignResult $preflight,
        int $workflowStageId,
    ): array {
        $changesApi = new WorkflowStageChangeApi($this->client, $spaceId);

        $assigned = [];
        $errors = [];

        /** @var Story $story */
        foreach ($preflight->stories as $story) {
            if ($story->hasWorkflowStage()) {
                continue;
            }

            try {
                /** @var int $storyId */
                $storyId = $story->get('id');
                /** @var string $storyName */
                $storyName = $story->get('name');
                $response = $changesApi->create(
                    WorkflowStageChange::makeFromParams(
                        $storyId,
                        $workflowStageId,
                    ),
                );
                $assigned[] = [
                    'name' => $storyName,
                    'stageId' => $response->data()->workflowStageId(),
                ];
            } catch (\Exception $e) {
                /** @var string $storyName */
                $storyName = $story->get('name');
                $errors[] = 'Error assigning workflow stage to story "'
                    . $storyName . '": ' . $e->getMessage();
                sleep(1);
            }
        }

        return ['assigned' => $assigned, 'errors' => $errors];
    }
}
