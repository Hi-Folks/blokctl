<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

use Storyblok\ManagementApi\Data\Story;

final readonly class StoryShowResult
{
    /**
     * @param array<string, mixed> $fullResponse
     */
    public function __construct(
        public Story $story,
        public array $fullResponse,
    ) {}
}
