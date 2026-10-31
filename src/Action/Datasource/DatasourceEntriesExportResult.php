<?php

declare(strict_types=1);

namespace Blokctl\Action\Datasource;

final readonly class DatasourceEntriesExportResult
{
    /**
     * @param list<string> $header name, value, then the dimension codes
     * @param list<list<string>> $rows One row per entry, in datasource order
     */
    public function __construct(
        public array $header,
        public array $rows,
    ) {}

    public function count(): int
    {
        return count($this->rows);
    }

    public function toCsv(): string
    {
        return DatasourceEntriesCsv::format($this->header, $this->rows);
    }
}
