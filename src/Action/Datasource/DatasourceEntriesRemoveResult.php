<?php

declare(strict_types=1);

namespace Blokctl\Action\Datasource;

final readonly class DatasourceEntriesRemoveResult
{
    /** Will be deleted (preflight). */
    public const string PLANNED = 'planned';

    /** No entry with this name: nothing to delete. */
    public const string NOT_FOUND = 'not_found';

    public const string REMOVED = 'removed';

    public const string FAILED = 'failed';

    /**
     * @param list<array{name: string, id: int|null, status: string, message: string}> $entries
     */
    public function __construct(
        public array $entries,
    ) {}

    /**
     * @return list<array{name: string, id: int|null, status: string, message: string}>
     */
    public function entriesWithStatus(string $status): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn(array $entry): bool => $entry['status'] === $status,
        ));
    }

    public function hasFailures(): bool
    {
        return $this->entriesWithStatus(self::FAILED) !== [];
    }
}
