<?php

declare(strict_types=1);

namespace Blokctl\Action\Datasource;

use Blokctl\ApiError;
use Storyblok\ManagementApi\Endpoints\ManagementApi;
use Storyblok\ManagementApi\ManagementApiClient;

/**
 * Read datasources and datasource entries as plain arrays, as returned by the Management API.
 */
final readonly class DatasourceLookup
{
    private const int PER_PAGE = 100;

    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * @return list<array<string, mixed>>
     *
     * @throws \RuntimeException if the datasources cannot be fetched
     */
    public function datasources(string $spaceId): array
    {
        return $this->pages(sprintf('spaces/%s/datasources', $spaceId), [], 'datasources');
    }

    /**
     * Find a datasource by exact slug or numeric ID.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException if no datasource matches
     */
    public function find(string $spaceId, string $slugOrId): array
    {
        $slugOrId = trim($slugOrId);
        foreach ($this->datasources($spaceId) as $datasource) {
            $id = $datasource['id'] ?? null;
            if (($datasource['slug'] ?? null) === $slugOrId || (is_scalar($id) && (string) $id === $slugOrId)) {
                return $datasource;
            }
        }

        throw new \RuntimeException('Datasource not found: ' . $slugOrId);
    }

    /**
     * Entries of a datasource, in datasource order. With $dimensionId, each entry's
     * dimension_value holds the value for that dimension.
     *
     * @return list<array<string, mixed>>
     *
     * @throws \RuntimeException if the entries cannot be fetched
     */
    public function entries(string $spaceId, int $datasourceId, ?int $dimensionId = null): array
    {
        $query = ['datasource_id' => $datasourceId];
        if ($dimensionId !== null) {
            $query['dimension'] = $dimensionId;
        }

        return $this->pages(sprintf('spaces/%s/datasource_entries', $spaceId), $query, 'datasource_entries');
    }

    /**
     * Dimensions of a datasource as code (entry_value) => dimension.
     *
     * @param array<string, mixed> $datasource
     * @return array<string, array<string, mixed>>
     */
    public static function dimensions(array $datasource): array
    {
        $dimensions = [];
        foreach (is_array($datasource['dimensions'] ?? null) ? $datasource['dimensions'] : [] as $dimension) {
            $code = is_array($dimension) ? ($dimension['entry_value'] ?? null) : null;
            if (is_array($dimension) && is_string($code)) {
                /** @var array<string, mixed> $dimension */
                $dimensions[$code] = $dimension;
            }
        }

        return $dimensions;
    }

    /**
     * @param array<string, mixed> $query
     * @return list<array<string, mixed>>
     */
    private function pages(string $path, array $query, string $key): array
    {
        $api = new ManagementApi($this->client);
        $items = [];
        $page = 1;
        do {
            $response = $api->get($path, [...$query, 'page' => $page, 'per_page' => self::PER_PAGE]);
            if (!$response->isOk()) {
                throw new \RuntimeException(sprintf('Failed to fetch %s: %s', str_replace('_', ' ', $key), ApiError::message($response)));
            }

            $data = $response->toArray();
            $batch = is_array($data[$key] ?? null) ? $data[$key] : [];
            foreach ($batch as $item) {
                if (is_array($item)) {
                    /** @var array<string, mixed> $item */
                    $items[] = $item;
                }
            }

            $page++;
        } while (count($batch) === self::PER_PAGE);

        return $items;
    }
}
