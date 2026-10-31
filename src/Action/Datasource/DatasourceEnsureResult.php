<?php

declare(strict_types=1);

namespace Blokctl\Action\Datasource;

final readonly class DatasourceEnsureResult
{
    public const string CREATED = 'created';

    public const string UPDATED = 'updated';

    public const string UNCHANGED = 'unchanged';

    /**
     * @param array<string, mixed> $datasource Datasource object as returned by the Management API
     * @param list<string> $changedFields
     */
    public function __construct(
        public string $status,
        public array $datasource,
        public array $changedFields,
    ) {}

    public function changed(): bool
    {
        return $this->status !== self::UNCHANGED;
    }

    public function datasourceId(): int
    {
        $id = $this->datasource['id'] ?? null;

        return is_numeric($id) ? (int) $id : 0;
    }
}
