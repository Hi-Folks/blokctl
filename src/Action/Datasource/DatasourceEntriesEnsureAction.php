<?php

declare(strict_types=1);

namespace Blokctl\Action\Datasource;

use Blokctl\ApiError;
use Storyblok\ManagementApi\Endpoints\ManagementApi;
use Storyblok\ManagementApi\ManagementApiClient;

/**
 * Create datasource entries, or update the value and dimension values of existing ones.
 *
 * Entries are matched by name (the entry key). New entries are appended in the
 * declared order. Entries that are not declared are kept, and existing entries are
 * not reordered. Failures are collected per entry.
 */
final readonly class DatasourceEntriesEnsureAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * @param string $datasource Datasource slug or ID
     * @param list<array{name: string, value?: string, dimensions?: array<string, string>}> $entries
     *        value: omit to keep the current value of an existing entry (required to create one);
     *        dimensions: dimension code => value for that dimension
     *
     * @throws \RuntimeException if the datasource or a dimension code is not found, an entry name is
     *                           declared twice, or the current entries cannot be fetched
     */
    public function execute(string $spaceId, string $datasource, array $entries): DatasourceEntriesEnsureResult
    {
        $lookup = new DatasourceLookup($this->client);
        $datasourceData = $lookup->find($spaceId, $datasource);
        $datasourceId = is_numeric($datasourceData['id'] ?? null) ? (int) $datasourceData['id'] : 0;
        $dimensions = DatasourceLookup::dimensions($datasourceData);

        $names = array_map(static fn(array $entry): string => $entry['name'], $entries);
        $duplicates = array_unique(array_diff_assoc($names, array_unique($names)));
        if ($duplicates !== []) {
            throw new \RuntimeException('Duplicate datasource entry name: ' . implode(', ', $duplicates));
        }

        $usedCodes = [];
        foreach ($entries as $entry) {
            foreach (array_keys($entry['dimensions'] ?? []) as $code) {
                if (!isset($dimensions[$code])) {
                    throw new \RuntimeException(sprintf('Dimension "%s" not found in datasource "%s".', $code, $datasource));
                }

                $usedCodes[$code] = true;
            }
        }

        $existing = $this->byName($lookup->entries($spaceId, $datasourceId));
        $dimensionValues = [];
        foreach (array_keys($usedCodes) as $code) {
            $dimensionValues[$code] = $this->byName($lookup->entries($spaceId, $datasourceId, $this->dimensionId($dimensions[$code])));
        }

        $results = [];
        foreach ($entries as $entry) {
            try {
                $results[] = $this->ensure($spaceId, $datasourceId, $entry, $existing[$entry['name']] ?? null, $dimensions, $dimensionValues);
            } catch (\RuntimeException $runtimeException) {
                $results[] = ['name' => $entry['name'], 'status' => DatasourceEntriesEnsureResult::FAILED, 'changed' => [], 'message' => $runtimeException->getMessage()];
            }
        }

        return new DatasourceEntriesEnsureResult($datasourceId, $results);
    }

    /**
     * @param array{name: string, value?: string, dimensions?: array<string, string>} $entry
     * @param array<string, mixed>|null $current
     * @param array<string, array<string, mixed>> $dimensions
     * @param array<string, array<string, array<string, mixed>>> $dimensionValues Code => name => entry with dimension_value
     * @return array{name: string, status: string, changed: list<string>, message: string}
     */
    private function ensure(string $spaceId, int $datasourceId, array $entry, ?array $current, array $dimensions, array $dimensionValues): array
    {
        $changed = [];
        if ($current === null) {
            if (!array_key_exists('value', $entry)) {
                throw new \RuntimeException('A value is required to create the entry.');
            }

            $response = new ManagementApi($this->client)->makeRequest('POST', sprintf('/v1/spaces/%s/datasource_entries', $spaceId), [
                'json' => ['datasource_entry' => ['datasource_id' => $datasourceId, 'name' => $entry['name'], 'value' => $entry['value']]],
            ]);
            $created = $response->isOk() ? ($response->toArray()['datasource_entry'] ?? null) : null;
            if (!is_array($created) || !is_numeric($created['id'] ?? null)) {
                throw new \RuntimeException('Failed to create entry: ' . ApiError::message($response));
            }

            $entryId = (int) $created['id'];
            $status = DatasourceEntriesEnsureResult::CREATED;
        } else {
            $entryId = is_numeric($current['id'] ?? null) ? (int) $current['id'] : 0;
            $status = DatasourceEntriesEnsureResult::UNCHANGED;
            if (array_key_exists('value', $entry) && $this->stringValue($current['value'] ?? '') !== $entry['value']) {
                $this->update($spaceId, $entryId, ['datasource_entry' => ['value' => $entry['value']]]);
                $changed[] = 'value';
            }
        }

        foreach ($entry['dimensions'] ?? [] as $code => $value) {
            $currentValue = $current === null ? '' : $this->stringValue($dimensionValues[$code][$entry['name']]['dimension_value'] ?? '');
            if ($currentValue !== $value) {
                $this->update($spaceId, $entryId, [
                    'datasource_entry' => ['dimension_value' => $value],
                    'dimension_id' => $this->dimensionId($dimensions[$code]),
                ]);
                $changed[] = 'dimension:' . $code;
            }
        }

        if ($status === DatasourceEntriesEnsureResult::UNCHANGED && $changed !== []) {
            $status = DatasourceEntriesEnsureResult::UPDATED;
        }

        return ['name' => $entry['name'], 'status' => $status, 'changed' => $changed, 'message' => ''];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function update(string $spaceId, int $entryId, array $body): void
    {
        // Entry updates return 204 without a body.
        $response = new ManagementApi($this->client)->makeRequest('PUT', sprintf('/v1/spaces/%s/datasource_entries/%d', $spaceId, $entryId), ['json' => $body]);
        if (!$response->isOk()) {
            throw new \RuntimeException('Failed to update entry: ' . ApiError::message($response));
        }
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return array<string, array<string, mixed>>
     */
    private function byName(array $entries): array
    {
        $byName = [];
        foreach ($entries as $entry) {
            if (is_string($entry['name'] ?? null)) {
                $byName[$entry['name']] = $entry;
            }
        }

        return $byName;
    }

    /**
     * @param array<string, mixed> $dimension
     */
    private function dimensionId(array $dimension): int
    {
        return is_numeric($dimension['id'] ?? null) ? (int) $dimension['id'] : 0;
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
