<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

final readonly class StoriesBulkCreateResult
{
    /**
     * @param array<array{file: string, name: string, slug: string, id: int, fullSlug: string}> $created
     * @param array<array{file: string, error: string}> $errors
     */
    public function __construct(
        public array $created = [],
        public array $errors = [],
    ) {}

    public function count(): int
    {
        return count($this->created);
    }

    public function errorCount(): int
    {
        return count($this->errors);
    }
}
