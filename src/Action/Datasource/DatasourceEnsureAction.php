<?php

declare(strict_types=1);

namespace Blokctl\Action\Datasource;

use Blokctl\ApiError;
use Storyblok\ManagementApi\Endpoints\ManagementApi;
use Storyblok\ManagementApi\ManagementApiClient;

/**
 * Create a datasource, or update the name and dimensions of an existing one.
 *
 * The datasource is matched by slug. Declared dimensions (matched by code, the
 * dimension's entry_value) are added or renamed; dimensions that are not declared
 * are kept.
 */
final readonly class DatasourceEnsureAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * @param string|null $name Display name; null keeps the existing name (the slug is used to create)
     * @param array<string, string>|null $dimensions Dimension code => dimension name, e.g. ['it' => 'Italian']
     *
     * @throws \RuntimeException if the API request fails
     */
    public function execute(string $spaceId, string $slug, ?string $name = null, ?array $dimensions = null): DatasourceEnsureResult
    {
        $slug = trim($slug);
        if ($slug === '') {
            throw new \RuntimeException('Datasource slug cannot be empty.');
        }

        $existing = null;
        foreach (new DatasourceLookup($this->client)->datasources($spaceId) as $datasource) {
            if (($datasource['slug'] ?? null) === $slug) {
                $existing = $datasource;
            }
        }

        if ($existing === null) {
            $payload = ['name' => $name ?? $slug, 'slug' => $slug];
            $attributes = $this->dimensionAttributes([], $dimensions ?? []);
            if ($attributes !== []) {
                $payload['dimensions_attributes'] = $attributes;
            }

            $datasource = $this->send('POST', sprintf('/v1/spaces/%s/datasources', $spaceId), $payload, $slug);

            return new DatasourceEnsureResult(DatasourceEnsureResult::CREATED, $datasource, $this->fieldNames(array_keys($payload)));
        }

        $changes = [];
        if ($name !== null && ($existing['name'] ?? null) !== $name) {
            $changes['name'] = $name;
        }

        $attributes = $this->dimensionAttributes(DatasourceLookup::dimensions($existing), $dimensions ?? []);
        if ($attributes !== []) {
            $changes['dimensions_attributes'] = $attributes;
        }

        if ($changes === []) {
            return new DatasourceEnsureResult(DatasourceEnsureResult::UNCHANGED, $existing, []);
        }

        $id = is_numeric($existing['id'] ?? null) ? (int) $existing['id'] : 0;
        $datasource = $this->send('PUT', sprintf('/v1/spaces/%s/datasources/%d', $spaceId, $id), $changes, $slug);

        return new DatasourceEnsureResult(DatasourceEnsureResult::UPDATED, $datasource, $this->fieldNames(array_keys($changes)));
    }

    /**
     * @param list<string> $keys Payload keys
     * @return list<string> Field names for the result, with dimensions_attributes reported as dimensions
     */
    private function fieldNames(array $keys): array
    {
        return array_map(static fn(string $key): string => $key === 'dimensions_attributes' ? 'dimensions' : $key, $keys);
    }

    /**
     * Dimension attributes to send: new dimensions, and renamed existing ones (by ID).
     *
     * @param array<string, array<string, mixed>> $existing Code => existing dimension
     * @param array<string, string> $declared Code => name
     * @return list<array<string, mixed>>
     */
    private function dimensionAttributes(array $existing, array $declared): array
    {
        $attributes = [];
        foreach ($declared as $code => $dimensionName) {
            $code = trim((string) $code);
            $dimensionName = trim($dimensionName) === '' ? $code : trim($dimensionName);
            $current = $existing[$code] ?? null;
            if ($current === null) {
                $attributes[] = ['name' => $dimensionName, 'entry_value' => $code];
            } elseif (($current['name'] ?? null) !== $dimensionName) {
                $attributes[] = ['id' => $current['id'] ?? null, 'name' => $dimensionName];
            }
        }

        return $attributes;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $payload, string $slug): array
    {
        $response = new ManagementApi($this->client)->makeRequest($method, $path, ['json' => ['datasource' => $payload]]);
        $datasource = $response->isOk() ? ($response->toArray()['datasource'] ?? null) : null;
        if (!is_array($datasource)) {
            throw new \RuntimeException(sprintf('Failed to save datasource "%s": %s', $slug, ApiError::message($response)));
        }

        /** @var array<string, mixed> $datasource */
        return $datasource;
    }
}
