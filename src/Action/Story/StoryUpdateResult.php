<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

use Storyblok\ManagementApi\Data\Story;

final readonly class StoryUpdateResult
{
    /**
     * @param array<string, mixed> $appliedContent The resolved content that was applied
     */
    public function __construct(
        public Story $story,
        public array $appliedContent,
    ) {}
}
