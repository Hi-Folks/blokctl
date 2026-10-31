<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

/**
 * Merge setup configurations for `extends`: the override wins.
 *
 * - Maps merge key by key, recursively.
 * - Named lists (see NAMED_LISTS) merge by identity: an item with the same name or
 *   slug is merged into the existing one, a new item is appended.
 * - Other lists of maps (operations such as story inserts, updates, or tag
 *   assignments) are concatenated: base operations first, then the override's.
 * - Lists of plain values (permissions, content types, slugs) are replaced as a whole.
 */
final class SpaceSetupConfigMerger
{
    /** List path (list items as `*`) => keys that identify an item. */
    public const array NAMED_LISTS = [
        'components.ensure' => ['name'],
        'components.ensure.*.fields' => ['field'],
        'components.ensure.*.fields.*.options' => ['name'],
        'components.fields' => ['component', 'field'],
        'components.fields.*.options' => ['name'],
        'datasources' => ['slug'],
        'datasources.*.entries' => ['name'],
        'roles' => ['name'],
        'workflow.ensure' => ['name'],
        'workflow.ensure.*.stages' => ['name'],
        'folders.ensure' => ['slug'],
        'stories.create' => ['slug'],
    ];

    /**
     * @param array<mixed> $base
     * @param array<mixed> $override
     * @return array<mixed>
     */
    public function merge(array $base, array $override): array
    {
        return $this->mergeValue($base, $override, '');
    }

    /**
     * @param array<mixed> $base
     * @param array<mixed> $override
     * @return array<mixed>
     */
    private function mergeValue(array $base, array $override, string $path): array
    {
        if (array_is_list($base) && array_is_list($override)) {
            return $this->mergeLists($base, $override, $path);
        }

        foreach ($override as $key => $value) {
            $base[$key] = is_array($value) && is_array($base[$key] ?? null)
                ? $this->mergeValue($base[$key], $value, $path === '' ? (string) $key : $path . '.' . $key)
                : $value;
        }

        return $base;
    }

    /**
     * @param list<mixed> $base
     * @param list<mixed> $override
     * @return list<mixed>
     */
    private function mergeLists(array $base, array $override, string $path): array
    {
        if (!$this->isListOfMaps($base) || !$this->isListOfMaps($override)) {
            return $override;
        }

        $identityKeys = self::NAMED_LISTS[$path] ?? null;
        if ($identityKeys === null) {
            return [...$base, ...$override];
        }

        $merged = $base;
        $positions = [];
        foreach ($merged as $index => $item) {
            $identity = $this->identity($item, $identityKeys);
            if ($identity !== null) {
                $positions[$identity] = $index;
            }
        }

        foreach ($override as $item) {
            $identity = $this->identity($item, $identityKeys);
            if ($identity !== null && isset($positions[$identity])) {
                $current = $merged[$positions[$identity]];
                $merged[$positions[$identity]] = is_array($current) && is_array($item)
                    ? $this->mergeValue($current, $item, $path . '.*')
                    : $item;
                continue;
            }

            $merged[] = $item;
            if ($identity !== null) {
                $positions[$identity] = count($merged) - 1;
            }
        }

        return array_values($merged);
    }

    /**
     * @param list<mixed> $items
     */
    private function isListOfMaps(array $items): bool
    {
        return array_all($items, fn($item): bool => is_array($item) && ($item === [] || !array_is_list($item)));
    }

    /**
     * @param list<string> $keys
     */
    private function identity(mixed $item, array $keys): ?string
    {
        if (!is_array($item)) {
            return null;
        }

        $parts = [];
        foreach ($keys as $key) {
            if (!is_scalar($item[$key] ?? null) || (string) $item[$key] === '') {
                return null;
            }

            $parts[] = (string) $item[$key];
        }

        return implode("\0", $parts);
    }
}
