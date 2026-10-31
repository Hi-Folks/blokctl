<?php

declare(strict_types=1);

namespace Blokctl\Action\Component;

use Blokctl\ApiError;
use Storyblok\ManagementApi\Endpoints\ManagementApi;
use Storyblok\ManagementApi\ManagementApiClient;

/**
 * Create a component, or update the declared settings of an existing one.
 *
 * The component is matched by its technical name. Every setting is optional: null
 * keeps the existing value. Updates never send the schema, because a schema in an
 * update replaces all fields; manage fields with ComponentFieldAddAction or
 * components.fields in space:setup.
 */
final readonly class ComponentEnsureAction
{
    public const string CONTENT_TYPE = 'content_type';

    public const string NESTABLE = 'nestable';

    public const string UNIVERSAL = 'universal';

    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * @param string|null $type content_type, nestable, or universal (defaults to nestable when creating)
     * @param string|null $folder Component folder path, e.g. Marketing/Banners (created when missing)
     *
     * @throws \RuntimeException if the type is invalid, the folder cannot be ensured, or the API request fails
     */
    public function execute(
        string $spaceId,
        string $name,
        ?string $displayName = null,
        ?string $type = null,
        ?string $folder = null,
        ?string $color = null,
        ?string $icon = null,
        ?string $previewField = null,
        ?string $description = null,
    ): ComponentEnsureResult {
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('Component name cannot be empty.');
        }

        $declared = array_filter([
            'display_name' => $displayName,
            'color' => $color,
            'icon' => $icon,
            'preview_field' => $previewField,
            'description' => $description,
        ], static fn(?string $value): bool => $value !== null);

        if ($type !== null) {
            [$declared['is_root'], $declared['is_nestable']] = match ($type) {
                self::CONTENT_TYPE => [true, false],
                self::NESTABLE => [false, true],
                self::UNIVERSAL => [true, true],
                default => throw new \RuntimeException('Invalid component type "' . $type . '". Use content_type, nestable, or universal.'),
            };
        }

        $existing = $this->find($spaceId, $name);
        if ($folder !== null && trim($folder) !== '') {
            $declared['component_group_uuid'] = new ComponentFolderEnsureAction($this->client)->execute($spaceId, $folder)->uuid();
        }

        if ($existing === null) {
            $payload = ['name' => $name, 'is_root' => false, 'is_nestable' => true, ...$declared, 'schema' => new \stdClass()];
            $component = $this->send('POST', sprintf('/v1/spaces/%s/components', $spaceId), $payload, $name);
            unset($payload['schema']);

            return new ComponentEnsureResult(ComponentEnsureResult::CREATED, $component, array_keys($payload));
        }

        $changes = [];
        foreach ($declared as $key => $value) {
            if (($existing[$key] ?? null) !== $value) {
                $changes[$key] = $value;
            }
        }

        if ($changes === []) {
            return new ComponentEnsureResult(ComponentEnsureResult::UNCHANGED, $existing, []);
        }

        $id = is_numeric($existing['id'] ?? null) ? (int) $existing['id'] : 0;
        $component = $this->send('PUT', sprintf('/v1/spaces/%s/components/%d', $spaceId, $id), $changes, $name);

        return new ComponentEnsureResult(ComponentEnsureResult::UPDATED, $component, array_keys($changes));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $spaceId, string $name): ?array
    {
        $response = new ManagementApi($this->client)->get(sprintf('spaces/%s/components', $spaceId), ['search' => $name]);
        if (!$response->isOk()) {
            throw new \RuntimeException('Failed to fetch components: ' . ApiError::message($response));
        }

        $data = $response->toArray();
        foreach (is_array($data['components'] ?? null) ? $data['components'] : [] as $component) {
            if (is_array($component) && ($component['name'] ?? null) === $name) {
                /** @var array<string, mixed> $component */
                return $component;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $payload, string $name): array
    {
        $response = new ManagementApi($this->client)->makeRequest($method, $path, ['json' => ['component' => $payload]]);
        $component = $response->isOk() ? ($response->toArray()['component'] ?? null) : null;
        if (!is_array($component)) {
            throw new \RuntimeException(sprintf('Failed to save component "%s": %s', $name, ApiError::message($response)));
        }

        /** @var array<string, mixed> $component */
        return $component;
    }
}
