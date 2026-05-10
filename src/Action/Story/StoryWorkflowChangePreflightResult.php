<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

final readonly class StoryWorkflowChangePreflightResult
{
    /**
     * @param array<int|string, string> $workflowStages
     */
    public function __construct(
        public string $workflowId,
        public array $workflowStages,
    ) {}
}
