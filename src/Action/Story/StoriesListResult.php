<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

use Storyblok\ManagementApi\Data\Stories;

final readonly class StoriesListResult
{
    public function __construct(
        public Stories $stories,
    ) {}

    public function count(): int
    {
        return $this->stories->count();
    }
}
