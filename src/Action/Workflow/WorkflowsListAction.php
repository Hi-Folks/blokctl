<?php

declare(strict_types=1);

namespace Blokctl\Action\Workflow;

use Storyblok\ManagementApi\Endpoints\WorkflowApi;
use Storyblok\ManagementApi\Endpoints\WorkflowStageApi;
use Storyblok\ManagementApi\ManagementApiClient;

final readonly class WorkflowsListAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    public function execute(string $spaceId): WorkflowsListResult
    {
        $workflowApi = new WorkflowApi($this->client, $spaceId);
        $workflows = $workflowApi->list()->data();

        $stageApi = new WorkflowStageApi($this->client, $spaceId);

        /** @var array<int, array{id: int, name: string, isDefault: bool, stages: array<int, array{id: int, name: string, position: int}>}> $items */
        $items = [];

        /** @phpstan-ignore foreach.nonIterable */
        foreach ($workflows as $workflow) {
            /** @var int $workflowId */
            $workflowId = $workflow->get('id');
            /** @var string $workflowName */
            $workflowName = $workflow->get('name');
            $isDefault = $workflow->getBoolean('is_default');

            $stages = $stageApi->list((string) $workflowId)->data();

            /** @var array<int, array{id: int, name: string, position: int}> $stageItems */
            $stageItems = [];

            /** @phpstan-ignore foreach.nonIterable */
            foreach ($stages as $stage) {
                /** @var int $stageId */
                $stageId = $stage->get('id');
                /** @var string $stageName */
                $stageName = $stage->get('name');
                /** @var int $position */
                $position = $stage->get('position');
                $stageItems[] = [
                    'id' => $stageId,
                    'name' => $stageName,
                    'position' => $position,
                ];
            }

            $items[] = [
                'id' => $workflowId,
                'name' => $workflowName,
                'isDefault' => $isDefault,
                'stages' => $stageItems,
            ];
        }

        return new WorkflowsListResult(workflows: $items);
    }
}
