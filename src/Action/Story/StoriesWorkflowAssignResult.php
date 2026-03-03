<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

use Storyblok\ManagementApi\Data\Stories;

final readonly class StoriesWorkflowAssignResult
{
    /**
     * @param array<string, string> $workflowStages  [id => name] for interactive selection
     */
    public function __construct(
        public Stories $stories,
        public int $countWithoutStage,
        public ?string $defaultStageId = null,
        public array $workflowStages = [],
    ) {}
}
