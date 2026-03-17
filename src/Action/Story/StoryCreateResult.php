<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

use Storyblok\ManagementApi\Data\Story;

final readonly class StoryCreateResult
{
    public function __construct(
        public Story $story,
    ) {}
}
