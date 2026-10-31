<?php

declare(strict_types=1);

namespace Blokctl\Action\Datasource;

final readonly class DatasourceEntriesEnsureResult
{
    public const string CREATED = 'created';

    public const string UPDATED = 'updated';

    public const string UNCHANGED = 'unchanged';

    public const string FAILED = 'failed';

    /**
     * @param list<array{name: string, status: string, changed: list<string>, message: string}> $entries
     *        changed: "value" and "dimension:<code>" for the properties that were updated
     */
    public function __construct(
        public int $datasourceId,
        public array $entries,
    ) {}

    /**
     * @return list<array{name: string, status: string, changed: list<string>, message: string}>
     */
    public function entriesWithStatus(string $status): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn(array $entry): bool => $entry['status'] === $status,
        ));
    }

    public function count(string $status): int
    {
        return count($this->entriesWithStatus($status));
    }

    public function hasFailures(): bool
    {
        return $this->count(self::FAILED) > 0;
    }
}
