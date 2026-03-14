<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

use Storyblok\ManagementApi\Data\Story;

final readonly class StoryWorkflowChangeResult
{
    public function __construct(
        public Story $story,
        public string $workflowStageName,
        public int $workflowStageId,
        public ?int $previousWorkflowStageId = null,
    ) {}
}
