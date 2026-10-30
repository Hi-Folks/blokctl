<?php

declare(strict_types=1);

namespace Blokctl\Action\Component;

use Storyblok\ManagementApi\Endpoints\ComponentApi;
use Storyblok\ManagementApi\Endpoints\ManagementApi;
use Storyblok\ManagementApi\ManagementApiClient;

/**
 * Compare the components (settings, fields, default values, and optionally
 * presets) of two spaces. Read-only: nothing is changed in either space.
 */
final readonly class ComponentsDiffAction
{
    /** Keys that differ between spaces by nature and are never compared. */
    private const array IGNORED_FIELD_KEYS = ['id', 'uuid', '_uid', 'pos', 'created_at', 'updated_at'];

    /** Keys ignored inside preset content (field values must stay comparable). */
    private const array IGNORED_CONTENT_KEYS = ['_uid', '_editable'];

    /** Component-level settings compared between spaces. */
    private const array SETTINGS = [
        'display_name',
        'description',
        'preview_field',
        'preview_tmpl',
        'is_root',
        'is_nestable',
        'color',
        'icon',
        'content_type_asset_preview',
    ];

    /**
     * @param ManagementApiClient $client Client for the source space
     * @param ManagementApiClient|null $targetClient Client for the target space (defaults to $client, e.g. when the target is in another region)
     */
    public function __construct(
        private ManagementApiClient $client,
        private ?ManagementApiClient $targetClient = null,
    ) {}

    /**
     * @param list<string> $componentNames Limit the comparison to these components (empty = all)
     *
     * @throws \RuntimeException if components or presets cannot be fetched, or a requested component exists in neither space
     */
    public function execute(
        string $sourceSpaceId,
        string $targetSpaceId,
        array $componentNames = [],
        bool $withPresets = false,
    ): ComponentsDiffResult {
        $targetClient = $this->targetClient ?? $this->client;

        $source = $this->fetchComponents($this->client, $sourceSpaceId);
        $target = $this->fetchComponents($targetClient, $targetSpaceId);

        if ($componentNames !== []) {
            $missing = array_diff($componentNames, array_keys($source), array_keys($target));
            if ($missing !== []) {
                throw new \RuntimeException(
                    'Component not found in either space: ' . implode(', ', $missing),
                );
            }

            $source = array_intersect_key($source, array_flip($componentNames));
            $target = array_intersect_key($target, array_flip($componentNames));
        }

        $sourcePresets = [];
        $targetPresets = [];
        if ($withPresets) {
            $sourcePresets = $this->fetchPresets($this->client, $sourceSpaceId, $source);
            $targetPresets = $this->fetchPresets($targetClient, $targetSpaceId, $target);
        }

        $onlyInSource = array_values(array_diff(array_keys($source), array_keys($target)));
        $onlyInTarget = array_values(array_diff(array_keys($target), array_keys($source)));
        sort($onlyInSource);
        sort($onlyInTarget);

        $common = array_values(array_intersect(array_keys($source), array_keys($target)));
        sort($common);

        $differences = [];
        $identical = [];
        foreach ($common as $name) {
            $changes = [];
            $this->compare(
                $this->normalizeComponent($source[$name], $sourcePresets[$name] ?? null),
                $this->normalizeComponent($target[$name], $targetPresets[$name] ?? null),
                '',
                $changes,
            );

            if ($changes === []) {
                $identical[] = $name;
            } else {
                $differences[$name] = $changes;
            }
        }

        return new ComponentsDiffResult(
            sourceSpaceId: $sourceSpaceId,
            targetSpaceId: $targetSpaceId,
            onlyInSource: $onlyInSource,
            onlyInTarget: $onlyInTarget,
            differences: $differences,
            identical: $identical,
            presetsCompared: $withPresets,
        );
    }

    /**
     * Fetch all components of a space, keyed by name, with the group name resolved.
     *
     * @return array<string, array<string, mixed>>
     */
    private function fetchComponents(ManagementApiClient $client, string $spaceId): array
    {
        $response = new ComponentApi($client, $spaceId)->all();
        if (!$response->isOk()) {
            throw new \RuntimeException(
                'Failed to fetch components of space ' . $spaceId . ': ' . $response->getErrorMessage(),
            );
        }

        $data = $response->toArray();

        $groups = [];
        $rawGroups = is_array($data['component_groups'] ?? null) ? $data['component_groups'] : [];
        foreach ($rawGroups as $group) {
            if (is_array($group) && is_string($group['uuid'] ?? null) && is_string($group['name'] ?? null)) {
                $groups[$group['uuid']] = $group['name'];
            }
        }

        $components = [];
        $rawComponents = is_array($data['components'] ?? null) ? $data['components'] : [];
        foreach ($rawComponents as $component) {
            if (!is_array($component)) {
                continue;
            }

            $name = $component['name'] ?? null;
            if (!is_string($name)) {
                continue;
            }

            /** @var array<string, mixed> $component */
            $groupUuid = $component['component_group_uuid'] ?? null;
            $component['group'] = is_string($groupUuid) ? ($groups[$groupUuid] ?? $groupUuid) : null;
            $components[$name] = $component;
        }

        ksort($components);

        return $components;
    }

    /**
     * Fetch presets of a space, grouped by component name and keyed by preset name.
     *
     * @param array<string, array<string, mixed>> $components
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function fetchPresets(ManagementApiClient $client, string $spaceId, array $components): array
    {
        $response = new ManagementApi($client)->get(sprintf('spaces/%s/presets', $spaceId));
        if (!$response->isOk()) {
            throw new \RuntimeException(
                'Failed to fetch presets of space ' . $spaceId . ': ' . $response->getErrorMessage(),
            );
        }

        $namesById = [];
        foreach ($components as $name => $component) {
            $id = $component['id'] ?? null;
            if (is_int($id) || is_string($id)) {
                $namesById[(string) $id] = $name;
            }
        }

        $data = $response->toArray();
        $rawPresets = is_array($data['presets'] ?? null) ? $data['presets'] : [];

        $presets = [];
        foreach ($rawPresets as $preset) {
            if (!is_array($preset)) {
                continue;
            }

            if (!is_string($preset['name'] ?? null)) {
                continue;
            }

            $componentId = $preset['component_id'] ?? null;
            $componentName = (is_int($componentId) || is_string($componentId))
                ? ($namesById[(string) $componentId] ?? null)
                : null;
            if ($componentName === null) {
                continue;
            }

            $key = $preset['name'];
            $suffix = 2;
            while (isset($presets[$componentName][$key])) {
                $key = $preset['name'] . ' #' . $suffix++;
            }

            $presets[$componentName][$key] = [
                'description' => $preset['description'] ?? null,
                'content' => $this->strip($preset['preset'] ?? null, self::IGNORED_CONTENT_KEYS),
            ];
        }

        foreach ($presets as &$componentPresets) {
            ksort($componentPresets);
        }

        return $presets;
    }

    /**
     * Build a comparable representation of a component: settings, fields
     * (with the tab they belong to), and optionally presets.
     *
     * @param array<string, mixed> $component
     * @param array<string, array<string, mixed>>|null $presets
     * @return array<string, mixed>
     */
    private function normalizeComponent(array $component, ?array $presets): array
    {
        $settings = [];
        foreach (self::SETTINGS as $key) {
            $settings[$key] = $component[$key] ?? null;
        }

        $settings['group'] = $component['group'] ?? null;

        $tags = [];
        $rawTags = is_array($component['internal_tags_list'] ?? null) ? $component['internal_tags_list'] : [];
        foreach ($rawTags as $tag) {
            if (is_array($tag) && is_string($tag['name'] ?? null)) {
                $tags[] = $tag['name'];
            }
        }

        sort($tags);
        $settings['tags'] = $tags;

        $schema = is_array($component['schema'] ?? null) ? $component['schema'] : [];

        $tabsByField = [];
        foreach ($schema as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (($entry['type'] ?? null) !== 'tab') {
                continue;
            }

            $keys = is_array($entry['keys'] ?? null) ? $entry['keys'] : [];
            foreach ($keys as $fieldName) {
                if (is_string($fieldName)) {
                    $tabsByField[$fieldName] = $entry['display_name'] ?? null;
                }
            }
        }

        $fields = [];
        foreach ($schema as $fieldName => $field) {
            if (!is_array($field)) {
                continue;
            }

            if (($field['type'] ?? null) === 'tab') {
                continue;
            }

            $normalized = $this->strip($field, self::IGNORED_FIELD_KEYS);
            if (is_array($normalized)) {
                $normalized['tab'] = $tabsByField[$fieldName] ?? null;
            }

            $fields[(string) $fieldName] = $normalized;
        }

        ksort($fields);

        $normalized = [
            'settings' => $settings,
            'fields' => $fields,
        ];

        if ($presets !== null) {
            $normalized['presets'] = $presets;
        }

        return $normalized;
    }

    /**
     * Recursively remove the given keys from associative arrays.
     *
     * @param list<string> $ignoredKeys
     */
    private function strip(mixed $value, array $ignoredKeys): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, $ignoredKeys, true)) {
                continue;
            }

            $result[$key] = $this->strip($item, $ignoredKeys);
        }

        return $result;
    }

    /**
     * Recursively compare two values and collect leaf-level changes.
     *
     * @param list<array{path: string, type: string, source: mixed, target: mixed}> $changes
     */
    private function compare(mixed $source, mixed $target, string $path, array &$changes): void
    {
        if (is_array($source) && is_array($target)) {
            $bothLists = array_is_list($source) && array_is_list($target);
            if ($bothLists && count($source) !== count($target)) {
                $changes[] = ['path' => $path, 'type' => ComponentsDiffResult::CHANGED, 'source' => $source, 'target' => $target];
                return;
            }

            $keys = array_unique(array_merge(array_keys($source), array_keys($target)));
            foreach ($keys as $key) {
                $childPath = $path === '' ? (string) $key : $path . '.' . $key;
                if (!array_key_exists($key, $target)) {
                    $changes[] = ['path' => $childPath, 'type' => ComponentsDiffResult::ONLY_IN_SOURCE, 'source' => $source[$key], 'target' => null];
                } elseif (!array_key_exists($key, $source)) {
                    $changes[] = ['path' => $childPath, 'type' => ComponentsDiffResult::ONLY_IN_TARGET, 'source' => null, 'target' => $target[$key]];
                } else {
                    $this->compare($source[$key], $target[$key], $childPath, $changes);
                }
            }

            return;
        }

        if ($source !== $target) {
            $changes[] = ['path' => $path, 'type' => ComponentsDiffResult::CHANGED, 'source' => $source, 'target' => $target];
        }
    }
}
