<?php

declare(strict_types=1);

namespace Blokctl\Action\Workflow;

final readonly class WorkflowStageShowResult
{
    /**
     * @param array<string, mixed> $stage        Full stage data from the API
     * @param string               $workflowName The workflow this stage belongs to
     * @param int                  $workflowId   The workflow ID
     */
    public function __construct(
        public array $stage,
        public string $workflowName,
        public int $workflowId,
    ) {}
}
