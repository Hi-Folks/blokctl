<?php

declare(strict_types=1);

namespace Blokctl\Action\Workflow;

use Storyblok\ManagementApi\Endpoints\WorkflowApi;
use Storyblok\ManagementApi\Endpoints\WorkflowStageApi;
use Storyblok\ManagementApi\ManagementApiClient;

final readonly class WorkflowStageShowAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * Find a workflow stage by ID or by name.
     *
     * When searching by name, only the default workflow is searched unless
     * a specific workflow is identified via $workflowId or $workflowName.
     * When searching by ID, all workflows are searched (unless scoped).
     *
     * @throws \RuntimeException if the stage or workflow is not found
     */
    public function execute(
        string $spaceId,
        ?int $stageId = null,
        ?string $stageName = null,
        ?string $workflowId = null,
        ?string $workflowName = null,
    ): WorkflowStageShowResult {
        if ($workflowId !== null && $workflowName !== null) {
            throw new \RuntimeException('Provide only one of workflowId or workflowName');
        }

        $workflowApi = new WorkflowApi($this->client, $spaceId);
        $workflows = $workflowApi->list()->data();

        $stageApi = new WorkflowStageApi($this->client, $spaceId);

        // Determine which workflows to search
        $targetWorkflows = $this->resolveTargetWorkflows(
            $workflows,
            $workflowId,
            $workflowName,
            searchById: $stageId !== null,
        );

        foreach ($targetWorkflows as [$wfId, $wfName]) {
            $stages = $stageApi->list((string) $wfId)->data();

            /** @phpstan-ignore foreach.nonIterable */
            foreach ($stages as $stage) {
                /** @var int $id */
                $id = $stage->get('id');
                /** @var string $name */
                $name = $stage->get('name');

                if ($stageId !== null && $id === $stageId) {
                    return new WorkflowStageShowResult(
                        stage: $stage->toArray(),
                        workflowName: $wfName,
                        workflowId: $wfId,
                    );
                }

                if ($stageName !== null && strcasecmp($name, $stageName) === 0) {
                    return new WorkflowStageShowResult(
                        stage: $stage->toArray(),
                        workflowName: $wfName,
                        workflowId: $wfId,
                    );
                }
            }
        }

        $lookup = $stageId !== null
            ? 'ID: ' . $stageId
            : 'name: ' . $stageName;

        throw new \RuntimeException('Workflow stage not found with ' . $lookup);
    }

    /**
     * Resolve which workflows to search.
     *
     * - If workflowId is given, search only that workflow.
     * - If workflowName is given, search only the matching workflow.
     * - If searching by stage ID (no workflow constraint), search all workflows.
     * - If searching by stage name (no workflow constraint), search only the default workflow.
     *
     * @return array<int, array{int, string}> List of [workflowId, workflowName]
     *
     * @throws \RuntimeException if the specified workflow is not found
     */
    private function resolveTargetWorkflows(
        mixed $workflows,
        ?string $workflowId,
        ?string $workflowName,
        bool $searchById,
    ): array {
        // Explicit workflow ID
        if ($workflowId !== null) {
            /** @phpstan-ignore foreach.nonIterable */
            foreach ($workflows as $workflow) {
                /** @var int $wfId */
                $wfId = $workflow->get('id'); /** @phpstan-ignore method.nonObject */
                if ((string) $wfId === $workflowId) {
                    /** @var string $wfName */
                    $wfName = $workflow->get('name'); /** @phpstan-ignore method.nonObject */

                    return [[$wfId, $wfName]];
                }
            }

            throw new \RuntimeException('Workflow not found with ID: ' . $workflowId);
        }

        // Explicit workflow name
        if ($workflowName !== null) {
            /** @phpstan-ignore foreach.nonIterable */
            foreach ($workflows as $workflow) {
                /** @var string $wfName */
                $wfName = $workflow->get('name'); /** @phpstan-ignore method.nonObject */
                if (strcasecmp($wfName, $workflowName) === 0) {
                    /** @var int $wfId */
                    $wfId = $workflow->get('id'); /** @phpstan-ignore method.nonObject */

                    return [[$wfId, $wfName]];
                }
            }

            throw new \RuntimeException('Workflow not found with name: ' . $workflowName);
        }

        // Search by stage ID → search all workflows
        if ($searchById) {
            $all = [];
            /** @phpstan-ignore foreach.nonIterable */
            foreach ($workflows as $workflow) {
                /** @var int $wfId */
                $wfId = $workflow->get('id'); /** @phpstan-ignore method.nonObject */
                /** @var string $wfName */
                $wfName = $workflow->get('name'); /** @phpstan-ignore method.nonObject */
                $all[] = [$wfId, $wfName];
            }

            return $all;
        }

        // Search by stage name → default workflow only
        $defaultWorkflow = null;
        /** @phpstan-ignore foreach.nonIterable */
        foreach ($workflows as $workflow) {
            if ($defaultWorkflow === null) {
                $defaultWorkflow = $workflow;
            }

            if ($workflow->getBoolean('is_default')) { /** @phpstan-ignore method.nonObject */
                $defaultWorkflow = $workflow;
            }
        }

        if ($defaultWorkflow === null) {
            throw new \RuntimeException('No workflows found in this space');
        }

        /** @var int $wfId */
        $wfId = $defaultWorkflow->get('id'); /** @phpstan-ignore method.nonObject */
        /** @var string $wfName */
        $wfName = $defaultWorkflow->get('name'); /** @phpstan-ignore method.nonObject */

        return [[$wfId, $wfName]];
    }
}
