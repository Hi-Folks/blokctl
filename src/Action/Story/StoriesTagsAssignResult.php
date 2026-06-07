<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

final readonly class StoriesTagsAssignResult
{
    /**
     * @param array<array{name: string, tags: string}> $tagged
     * @param string[] $errors
     * @param array<array{name: string, tags: string}> $skipped
     */
    public function __construct(
        public array $tagged = [],
        public array $errors = [],
        public array $skipped = [],
    ) {}
}
