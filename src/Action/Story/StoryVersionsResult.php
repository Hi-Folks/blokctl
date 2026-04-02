<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

final readonly class StoryVersionsResult
{
    /**
     * @param array<int, array<string, mixed>> $versions
     */
    public function __construct(
        public array $versions,
        public string $storyId,
    ) {}

    public function count(): int
    {
        return count($this->versions);
    }
}
