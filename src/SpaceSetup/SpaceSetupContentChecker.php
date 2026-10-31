<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

use Blokctl\ApiError;
use Storyblok\ManagementApi\Endpoints\ManagementApi;
use Storyblok\ManagementApi\ManagementApiClient;

/**
 * Check story content in a setup config against component definitions, so a wrong
 * field name or value type is reported before Storyblok silently stores it.
 *
 * Checked: stories.create content (inline and content_file), stories.insert_component
 * components, and stories.update fields, including nested blocks. Definitions come from
 * the config (components.ensure and components.fields) and, with withSpace(), from the
 * components of a space. Expressions (${{ ... }}), asset directives, and `_asset` /
 * `_slug` markers are not type-checked.
 */
final class SpaceSetupContentChecker
{
    /** Keys of a block that are not schema fields. */
    private const array BLOCK_KEYS = ['component', '_uid', '_editable'];

    /** @var array<string, array<string, array<mixed>>> Component name => field name => field schema */
    private array $components = [];

    /** Whether the definitions include the space, so an unknown component is an error. */
    private bool $complete = false;

    /** @var list<string> */
    private array $errors = [];

    /** @var array<string, true> Components used in content but not defined (only when not complete) */
    private array $unchecked = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly array $config)
    {
        $components = is_array($config['components'] ?? null) ? $config['components'] : [];
        foreach (is_array($components['ensure'] ?? null) ? $components['ensure'] : [] as $definition) {
            if (is_array($definition) && is_string($definition['name'] ?? null)) {
                $this->components[$definition['name']] ??= [];
                foreach (is_array($definition['fields'] ?? null) ? $definition['fields'] : [] as $field) {
                    $this->addConfigField($definition['name'], $field);
                }
            }
        }

        foreach (is_array($components['fields'] ?? null) ? $components['fields'] : [] as $field) {
            if (is_array($field) && is_string($field['component'] ?? null)) {
                $this->addConfigField($field['component'], $field);
            }
        }
    }

    public function hasContentToCheck(): bool
    {
        $stories = is_array($this->config['stories'] ?? null) ? $this->config['stories'] : [];

        return (is_array($stories['create'] ?? null) && $stories['create'] !== [])
            || (is_array($stories['insert_component'] ?? null) && $stories['insert_component'] !== [])
            || (is_array($stories['update'] ?? null) && $stories['update'] !== []);
    }

    /**
     * Add the component definitions of a space (read-only). Fields declared in the config
     * take precedence. After this, a component that is neither in the space nor in the
     * config is reported as unknown.
     *
     * @throws \RuntimeException if the components cannot be fetched
     */
    public function withSpace(ManagementApiClient $client, string $spaceId): self
    {
        $response = new ManagementApi($client)->get(sprintf('spaces/%s/components', $spaceId));
        if (!$response->isOk()) {
            throw new \RuntimeException('Failed to fetch components: ' . ApiError::message($response));
        }

        $data = $response->toArray();
        foreach (is_array($data['components'] ?? null) ? $data['components'] : [] as $component) {
            if (!is_array($component) || !is_string($component['name'] ?? null)) {
                continue;
            }

            $fields = [];
            foreach (is_array($component['schema'] ?? null) ? $component['schema'] : [] as $name => $field) {
                if (is_array($field) && ($field['type'] ?? null) !== 'tab') {
                    $fields[(string) $name] = $field;
                }
            }

            $this->components[$component['name']] = [...$fields, ...($this->components[$component['name']] ?? [])];
        }

        $this->complete = true;

        return $this;
    }

    /**
     * Mark the configured definitions as complete, for example for a new blank space.
     */
    public function withOnlyConfiguredComponents(): self
    {
        $this->complete = true;

        return $this;
    }

    /**
     * Check all story content of the config.
     *
     * @param string $configDirectory Directory for relative content_file paths
     */
    public function check(string $configDirectory = '.'): SpaceSetupContentCheckResult
    {
        $this->errors = [];
        $this->unchecked = [];
        $stories = is_array($this->config['stories'] ?? null) ? $this->config['stories'] : [];

        foreach (is_array($stories['create'] ?? null) ? $stories['create'] : [] as $index => $create) {
            if (!is_array($create)) {
                continue;
            }

            $path = '$.stories.create[' . $index . ']';
            $content = $create['content'] ?? null;
            if (is_string($create['content_file'] ?? null) && !is_array($content)) {
                $content = $this->readContentFile($create['content_file'], $configDirectory, $path);
                $path .= '.content_file';
            } else {
                $path .= '.content';
            }

            if (is_array($content)) {
                $this->checkBlock($content, $path, true);
            }
        }

        foreach (is_array($stories['insert_component'] ?? null) ? $stories['insert_component'] : [] as $index => $insert) {
            if (is_array($insert) && is_array($insert['component'] ?? null)) {
                $this->checkBlock($insert['component'], '$.stories.insert_component[' . $index . '].component', true);
            }
        }

        foreach (is_array($stories['update'] ?? null) ? $stories['update'] : [] as $index => $update) {
            foreach (is_array($update) && is_array($update['components'] ?? null) ? $update['components'] : [] as $componentIndex => $componentUpdate) {
                if (!is_array($componentUpdate) || !is_string($componentUpdate['component'] ?? null) || !is_array($componentUpdate['fields'] ?? null)) {
                    continue;
                }

                $this->checkBlock(
                    ['component' => $componentUpdate['component'], ...$componentUpdate['fields']],
                    sprintf('$.stories.update[%d].components[%d].fields', $index, $componentIndex),
                    false,
                );
            }
        }

        return new SpaceSetupContentCheckResult($this->errors, array_keys($this->unchecked));
    }

    /**
     * @param array<mixed> $block
     * @param bool $requireFields Report missing required fields (create and insert; not partial updates)
     */
    private function checkBlock(array $block, string $path, bool $requireFields): void
    {
        $name = $block['component'] ?? null;
        if (!is_string($name) || $name === '') {
            $this->errors[] = $path . ': missing "component".';
            return;
        }

        $fields = $this->components[$name] ?? null;
        if ($fields === null) {
            if ($this->complete) {
                $this->errors[] = sprintf('%s: unknown component "%s".', $path, $name);
            } else {
                $this->unchecked[$name] = true;
            }

            return;
        }

        foreach ($block as $key => $value) {
            if (!is_string($key) || in_array($key, self::BLOCK_KEYS, true)) {
                continue;
            }

            $fieldPath = $path . '.' . $key;
            if (!isset($fields[$key])) {
                $suggestion = $this->closest($key, array_keys($fields));
                $this->errors[] = sprintf(
                    '%s: unknown field "%s" in component "%s"%s.',
                    $fieldPath,
                    $key,
                    $name,
                    $suggestion === null ? '' : ' (did you mean "' . $suggestion . '"?)',
                );
                continue;
            }

            $this->checkValue($fields[$key], $value, $fieldPath, $name, $requireFields);
        }

        if ($requireFields) {
            foreach ($fields as $fieldName => $field) {
                if (($field['required'] ?? false) === true && !array_key_exists($fieldName, $block)) {
                    $this->errors[] = sprintf('%s: required field "%s" of component "%s" is missing.', $path, $fieldName, $name);
                }
            }
        }
    }

    /**
     * @param array<mixed> $field
     */
    private function checkValue(array $field, mixed $value, string $path, string $component, bool $requireFields): void
    {
        if ($value === null || $this->isDynamic($value)) {
            return;
        }

        $type = is_string($field['type'] ?? null) ? $field['type'] : '';
        $problem = match ($type) {
            'text', 'textarea', 'markdown', 'datetime', 'option' => is_string($value) ? null : 'text',
            'number' => is_numeric($value) ? null : 'a number',
            'boolean' => is_bool($value) ? null : 'true or false',
            'options', 'multiasset' => is_array($value) && array_is_list($value) ? null : 'a list',
            'asset', 'multilink', 'richtext' => is_array($value) && !array_is_list($value) ? null : 'an object',
            'bloks' => is_array($value) && array_is_list($value) ? null : 'a list of blocks',
            default => null,
        };

        if ($problem !== null) {
            $this->errors[] = sprintf('%s: field "%s" of component "%s" is %s and needs %s.', $path, basename(str_replace('.', '/', $path)), $component, $type, $problem);
            return;
        }

        if ($type !== 'bloks' || !is_array($value)) {
            return;
        }

        $whitelist = ($field['restrict_components'] ?? false) === true && is_array($field['component_whitelist'] ?? null)
            ? array_values(array_filter($field['component_whitelist'], is_string(...)))
            : null;
        foreach ($value as $index => $block) {
            $blockPath = $path . '[' . $index . ']';
            if (!is_array($block)) {
                $this->errors[] = $blockPath . ': a block must be an object with "component".';
                continue;
            }

            if ($whitelist !== null && is_string($block['component'] ?? null) && !in_array($block['component'], $whitelist, true)) {
                $this->errors[] = sprintf('%s: component "%s" is not allowed here (allowed: %s).', $blockPath, $block['component'], implode(', ', $whitelist));
            }

            $this->checkBlock($block, $blockPath, $requireFields);
        }
    }

    /**
     * Values resolved at run time are not type-checked: expressions, asset directives, and markers.
     */
    private function isDynamic(mixed $value): bool
    {
        if (is_string($value)) {
            return str_contains($value, '${{');
        }

        return is_array($value) && (
            array_key_exists('_asset', $value)
            || array_key_exists('_slug', $value)
            || (is_array($value['asset'] ?? null) && (array_key_exists('_find', $value['asset']) || array_key_exists('_ref', $value['asset'])))
        );
    }

    private function addConfigField(string $component, mixed $field): void
    {
        if (!is_array($field) || !is_string($field['field'] ?? null)) {
            return;
        }

        $name = $field['field'];
        $properties = is_array($field['properties'] ?? null) ? $field['properties'] : [];
        unset($field['properties'], $field['component'], $field['field'], $field['tab']);
        $this->components[$component][$name] = [...$properties, ...$field];
    }

    /**
     * @return array<mixed>|null
     */
    private function readContentFile(string $file, string $directory, string $path): ?array
    {
        $fullPath = str_starts_with($file, DIRECTORY_SEPARATOR) ? $file : rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file;
        $json = is_file($fullPath) ? file_get_contents($fullPath) : false;
        if ($json === false) {
            $this->errors[] = $path . ': content file not found: ' . $file . '.';
            return null;
        }

        $content = json_decode($json, true);
        if (!is_array($content)) {
            $this->errors[] = $path . ': content file is not valid JSON: ' . $file . '.';
            return null;
        }

        return $content;
    }

    /**
     * @param list<string> $candidates
     */
    private function closest(string $name, array $candidates): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($candidates as $candidate) {
            $distance = levenshtein(strtolower($name), strtolower($candidate));
            if ($distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best !== null && $bestDistance <= max(2, intdiv(strlen($name), 3)) ? $best : null;
    }
}
