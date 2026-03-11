<?php

declare(strict_types=1);

namespace Blokctl\Action\Component;

final readonly class ComponentsUsageResult
{
    /**
     * @param array<string, array{stories: int, total: int}> $usage Component name => usage counts
     * @param int $storiesAnalyzed Total number of stories analyzed
     */
    public function __construct(
        public array $usage,
        public int $storiesAnalyzed,
    ) {}

    public function count(): int
    {
        return count($this->usage);
    }
}
