<?php

declare(strict_types=1);

namespace Blokctl\Action\Datasource;

use Storyblok\ManagementApi\ManagementApiClient;

/**
 * Export the entries of a datasource as CSV rows: name, value, and one column per
 * dimension code. The format is the one DatasourceEntriesCsv::load() and
 * datasource:entries-ensure --csv read, so an export can be edited and imported back.
 */
final readonly class DatasourceEntriesExportAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * @param string $datasource Datasource slug or ID
     * @param list<string>|null $dimensions Dimension codes to include; null includes all dimensions
     *
     * @throws \RuntimeException if the datasource or a dimension code is not found, or the entries cannot be fetched
     */
    public function execute(string $spaceId, string $datasource, ?array $dimensions = null): DatasourceEntriesExportResult
    {
        return $this->fromDatasource($spaceId, new DatasourceLookup($this->client)->find($spaceId, $datasource), $dimensions);
    }

    /**
     * Same as execute(), for a datasource already fetched (for example from DatasourceLookup::datasources()),
     * which saves the request that looks the datasource up.
     *
     * @param array<string, mixed> $datasourceData Datasource object as returned by the Management API
     * @param list<string>|null $dimensions Dimension codes to include; null includes all dimensions
     *
     * @throws \RuntimeException if a dimension code is not found, or the entries cannot be fetched
     */
    public function fromDatasource(string $spaceId, array $datasourceData, ?array $dimensions = null): DatasourceEntriesExportResult
    {
        $lookup = new DatasourceLookup($this->client);
        $datasource = $this->stringValue($datasourceData['slug'] ?? '');
        $datasourceId = is_numeric($datasourceData['id'] ?? null) ? (int) $datasourceData['id'] : 0;
        $available = DatasourceLookup::dimensions($datasourceData);
        $codes = $dimensions ?? array_map(strval(...), array_keys($available));
        foreach ($codes as $code) {
            if (!isset($available[$code])) {
                throw new \RuntimeException(sprintf('Dimension "%s" not found in datasource "%s".', $code, $datasource));
            }
        }

        $values = [];
        foreach ($codes as $code) {
            $dimensionId = is_numeric($available[$code]['id'] ?? null) ? (int) $available[$code]['id'] : 0;
            foreach ($lookup->entries($spaceId, $datasourceId, $dimensionId) as $entry) {
                if (is_string($entry['name'] ?? null)) {
                    $values[$code][$entry['name']] = $this->stringValue($entry['dimension_value'] ?? '');
                }
            }
        }

        $rows = [];
        foreach ($lookup->entries($spaceId, $datasourceId) as $entry) {
            $name = $this->stringValue($entry['name'] ?? '');
            $row = [$name, $this->stringValue($entry['value'] ?? '')];
            foreach ($codes as $code) {
                $row[] = $values[$code][$name] ?? '';
            }

            $rows[] = $row;
        }

        return new DatasourceEntriesExportResult(['name', 'value', ...$codes], $rows);
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
