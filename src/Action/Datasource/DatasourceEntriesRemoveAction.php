<?php

declare(strict_types=1);

namespace Blokctl\Action\Datasource;

use Blokctl\ApiError;
use Storyblok\ManagementApi\Endpoints\ManagementApi;
use Storyblok\ManagementApi\ManagementApiClient;

/**
 * Delete datasource entries by name.
 *
 * Names that do not match an entry are reported as not found, so running the same
 * removal again is safe.
 */
final readonly class DatasourceEntriesRemoveAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * Match the names against the current entries, without changing the datasource.
     *
     * @param string $datasource Datasource slug or ID
     * @param list<string> $names Entry names (keys)
     *
     * @throws \RuntimeException if no name is given, the datasource is not found, or the entries cannot be fetched
     */
    public function preflight(string $spaceId, string $datasource, array $names): DatasourceEntriesRemoveResult
    {
        $names = array_values(array_unique(array_filter(array_map(trim(...), $names), static fn(string $name): bool => $name !== '')));
        if ($names === []) {
            throw new \RuntimeException('At least one entry name is required.');
        }

        $lookup = new DatasourceLookup($this->client);
        $datasourceData = $lookup->find($spaceId, $datasource);
        $ids = [];
        foreach ($lookup->entries($spaceId, is_numeric($datasourceData['id'] ?? null) ? (int) $datasourceData['id'] : 0) as $entry) {
            if (is_string($entry['name'] ?? null) && is_numeric($entry['id'] ?? null)) {
                $ids[$entry['name']] = (int) $entry['id'];
            }
        }

        $entries = [];
        foreach ($names as $name) {
            $entries[] = [
                'name' => $name,
                'id' => $ids[$name] ?? null,
                'status' => isset($ids[$name]) ? DatasourceEntriesRemoveResult::PLANNED : DatasourceEntriesRemoveResult::NOT_FOUND,
                'message' => '',
            ];
        }

        return new DatasourceEntriesRemoveResult($entries);
    }

    /**
     * Delete every planned entry from the preflight. Failures are collected per entry.
     */
    public function execute(string $spaceId, DatasourceEntriesRemoveResult $preflight): DatasourceEntriesRemoveResult
    {
        $api = new ManagementApi($this->client);
        $entries = [];
        foreach ($preflight->entries as $entry) {
            if ($entry['status'] !== DatasourceEntriesRemoveResult::PLANNED || $entry['id'] === null) {
                $entries[] = $entry;
                continue;
            }

            $response = $api->delete(sprintf('spaces/%s/datasource_entries/%d', $spaceId, $entry['id']));
            $entries[] = $response->isOk()
                ? [...$entry, 'status' => DatasourceEntriesRemoveResult::REMOVED]
                : [...$entry, 'status' => DatasourceEntriesRemoveResult::FAILED, 'message' => ApiError::message($response)];
        }

        return new DatasourceEntriesRemoveResult($entries);
    }
}
