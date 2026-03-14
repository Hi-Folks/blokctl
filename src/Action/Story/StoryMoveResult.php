<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

use Storyblok\ManagementApi\Data\Story;

final readonly class StoryMoveResult
{
    public function __construct(
        public Story $story,
        public int $previousFolderId,
        public int $newFolderId,
        public string $previousFullSlug = '',
    ) {}
}
