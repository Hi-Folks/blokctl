<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

use Blokctl\Action\Datasource\DatasourceEntriesCsv;
use Blokctl\Action\Datasource\DatasourceEntriesExportAction;
use Blokctl\Action\Datasource\DatasourceLookup;
use Blokctl\Action\Space\SpaceRoleListAction;
use Blokctl\Action\Workflow\WorkflowLookup;
use Blokctl\ApiError;
use Storyblok\ManagementApi\Endpoints\ManagementApi;
use Storyblok\ManagementApi\ManagementApiClient;

/**
 * Export the configuration of an existing space in the space:setup format, so it can be
 * versioned and applied to another space.
 *
 * IDs are replaced with portable references: folder paths for components, story full
 * slugs for role paths, role and stage names for workflows. Settings the setup format
 * cannot express are reported as warnings instead of being dropped silently.
 */
final readonly class SpaceSetupExporter
{
    public const string COMPONENTS = 'components';

    public const string DATASOURCES = 'datasources';

    public const string ROLES = 'roles';

    public const string WORKFLOWS = 'workflows';

    public const array SECTIONS = [self::COMPONENTS, self::DATASOURCES, self::ROLES, self::WORKFLOWS];

    /** Field schema properties exported as setup keys. */
    private const array FIELD_PROPERTIES = [
        'display_name', 'required', 'translatable', 'field_type', 'customize_toolbar', 'description',
        'default_value', 'options', 'source', 'datasource_slug', 'component_whitelist', 'restrict_components', 'filetypes',
    ];

    /** Space role list properties that the setup format does not support. */
    private const array UNSUPPORTED_ROLE_PROPERTIES = [
        'field_permissions', 'allowed_field_permissions', 'readonly_field_permissions', 'datasource_ids', 'blocked_datasource_ids',
        'component_ids', 'allowed_component_ids', 'branch_ids', 'blocked_branch_ids', 'blocked_languages', 'asset_folder_ids',
        'blocked_asset_folder_ids', 'managed_component_ids', 'blocked_manage_component_ids', 'managed_component_group_uuids',
        'blocked_manage_component_group_uuids', 'component_group_uuids', 'blocked_component_group_uuids',
    ];

    /** Field, option, and entry names treated as secrets (matched as substrings, ignoring case, `_`, and `-`). */
    public const array DEFAULT_SECRET_NAMES = [
        'token', 'access_token', 'secret', 'client_secret', 'password', 'passwd', 'api_key', 'private_key', 'access_key', 'credential',
    ];

    public const string DEFAULT_SECRET_PLACEHOLDER = '####YOURSECRET####';

    /**
     * @param (\Closure(string): void)|null $progress Receives a short message for each step, e.g. to show progress on large spaces
     * @param list<string> $secretNames Names treated as secrets (substring match, ignoring case, `_`, and `-`)
     * @param string $secretPlaceholder Text that replaces secret values in the export
     */
    public function __construct(
        private ManagementApiClient $client,
        private ?\Closure $progress = null,
        private array $secretNames = self::DEFAULT_SECRET_NAMES,
        private string $secretPlaceholder = self::DEFAULT_SECRET_PLACEHOLDER,
    ) {}

    /**
     * @param list<string> $sections Sections to export (see SECTIONS)
     * @param list<string> $components Component names to export; empty exports all
     * @param list<string> $datasources Datasource slugs to export; empty exports all
     * @param string|null $entriesDirectory Write datasource entries as CSV files in this directory
     *                                      (relative to the config file) instead of inline
     * @param bool $includeSecrets Keep values that look like secrets; by default they are replaced
     *                             with the secret placeholder and listed as warnings
     *
     * @throws \RuntimeException if a section is unknown or the API requests fail
     */
    public function export(
        string $spaceId,
        array $sections = self::SECTIONS,
        array $components = [],
        array $datasources = [],
        ?string $entriesDirectory = null,
        bool $includeSecrets = false,
    ): SpaceSetupExportResult {
        $unknown = array_diff($sections, self::SECTIONS);
        if ($unknown !== []) {
            throw new \RuntimeException('Unknown section: ' . implode(', ', $unknown) . '. Use ' . implode(', ', self::SECTIONS) . '.');
        }

        $config = ['version' => 1];
        $warnings = [];
        $files = [];

        if (in_array(self::DATASOURCES, $sections, true)) {
            $this->report('Reading datasources...');
            [$config['datasources'], $files] = $this->datasources($spaceId, $datasources, $entriesDirectory, $includeSecrets, $warnings);
            $this->report(sprintf('Datasources: %d exported.', count($config['datasources'])));
        }

        if (in_array(self::COMPONENTS, $sections, true)) {
            $this->report('Reading components...');
            $config['components'] = ['ensure' => $this->components($spaceId, $components)];
            if (!$includeSecrets) {
                $config['components']['ensure'] = $this->maskComponentSecrets($config['components']['ensure'], $warnings);
            }

            $this->report(sprintf('Components: %d exported.', count($config['components']['ensure'])));
        }

        if (in_array(self::ROLES, $sections, true)) {
            $this->report('Reading roles...');
            $config['roles'] = $this->roles($spaceId, $warnings);
            $this->report(sprintf('Roles: %d exported.', count($config['roles'])));
        }

        if (in_array(self::WORKFLOWS, $sections, true)) {
            $this->report('Reading workflows...');
            $config['workflow'] = ['ensure' => $this->workflows($spaceId, $warnings)];
            $this->report(sprintf('Workflows: %d exported.', count($config['workflow']['ensure'])));
        }

        $config = array_filter($config, static fn(mixed $value): bool => $value !== [] && $value !== ['ensure' => []]);

        return new SpaceSetupExportResult($config, $warnings, $files);
    }

    /**
     * @param list<string> $only
     * @return list<array<string, mixed>>
     */
    private function components(string $spaceId, array $only): array
    {
        $response = new ManagementApi($this->client)->get(sprintf('spaces/%s/components', $spaceId));
        if (!$response->isOk()) {
            throw new \RuntimeException('Failed to fetch components: ' . ApiError::message($response));
        }

        $data = $response->toArray();
        $folderPaths = $this->folderPaths(is_array($data['component_groups'] ?? null) ? $data['component_groups'] : []);
        $exported = [];
        foreach (is_array($data['components'] ?? null) ? $data['components'] : [] as $component) {
            if (!is_array($component) || !is_string($component['name'] ?? null)) {
                continue;
            }

            $name = $component['name'];
            if ($only !== [] && !in_array($name, $only, true)) {
                continue;
            }

            $isRoot = ($component['is_root'] ?? false) === true;
            $isNestable = ($component['is_nestable'] ?? false) === true;
            $definition = ['name' => $name];
            $definition += $this->nonEmpty(['display_name' => $component['display_name'] ?? null]);
            $definition['type'] = $isRoot && $isNestable ? 'universal' : ($isRoot ? 'content_type' : 'nestable');
            $folder = $folderPaths[$this->string($component['component_group_uuid'] ?? '')] ?? null;
            if ($folder !== null) {
                $definition['folder'] = $folder;
            }

            $definition += $this->nonEmpty([
                'color' => $component['color'] ?? null,
                'icon' => $component['icon'] ?? null,
                'preview_field' => $component['preview_field'] ?? null,
                'description' => $component['description'] ?? null,
            ]);

            $fields = $this->componentFields(is_array($component['schema'] ?? null) ? $component['schema'] : []);
            if ($fields !== []) {
                $definition['fields'] = $fields;
            }

            $exported[] = $definition;
        }

        if ($only !== []) {
            $missing = array_diff($only, array_map($this->string(...), array_column($exported, 'name')));
            if ($missing !== []) {
                throw new \RuntimeException('Component not found: ' . implode(', ', $missing));
            }
        }

        return $exported;
    }

    /**
     * Fields in schema order, each with its tab and its properties. Properties without a
     * dedicated setup key are exported under `properties`.
     *
     * @param array<mixed> $schema
     * @return list<array<string, mixed>>
     */
    private function componentFields(array $schema): array
    {
        $tabs = [];
        $fields = [];
        foreach ($schema as $key => $field) {
            if (!is_array($field)) {
                continue;
            }

            if (($field['type'] ?? null) === 'tab') {
                foreach (is_array($field['keys'] ?? null) ? $field['keys'] : [] as $fieldKey) {
                    $tabs[$this->string($fieldKey)] = $this->string($field['display_name'] ?? '');
                }

                continue;
            }

            $fields[(string) $key] = $field;
        }

        uasort($fields, static fn(array $a, array $b): int => (is_numeric($a['pos'] ?? null) ? (int) $a['pos'] : 0) <=> (is_numeric($b['pos'] ?? null) ? (int) $b['pos'] : 0));

        $exported = [];
        foreach ($fields as $key => $field) {
            $entry = ['field' => $key, 'type' => $this->string($field['type'] ?? '')];
            if (isset($tabs[$key]) && $tabs[$key] !== '') {
                $entry['tab'] = $tabs[$key];
            }

            foreach (self::FIELD_PROPERTIES as $property) {
                $value = $field[$property] ?? null;
                if (in_array($value, [null, '', [], false], true)) {
                    continue;
                }

                $entry[$property] = $property === 'options' && is_array($value)
                    ? array_values(array_map(fn(mixed $option): array => [
                        'name' => $this->string(is_array($option) ? ($option['name'] ?? '') : $option),
                        'value' => $this->string(is_array($option) ? ($option['value'] ?? '') : $option),
                    ], $value))
                    : $value;
            }

            if (($field['type'] ?? null) === 'richtext' && array_key_exists('customize_toolbar', $field)) {
                $entry['customize_toolbar'] = (bool) $field['customize_toolbar'];
            }

            $properties = [];
            foreach ($field as $property => $value) {
                if (!in_array($property, ['id', 'pos', 'type', ...self::FIELD_PROPERTIES], true) && $value !== null && $value !== '' && $value !== []) {
                    $properties[(string) $property] = $value;
                }
            }

            if ($properties !== []) {
                $entry['properties'] = $properties;
            }

            $exported[] = $entry;
        }

        return $exported;
    }

    /**
     * @param array<mixed> $groups Component folders as returned by the API
     * @return array<string, string> Folder UUID => folder path
     */
    private function folderPaths(array $groups): array
    {
        $byId = [];
        foreach ($groups as $group) {
            if (is_array($group) && is_numeric($group['id'] ?? null)) {
                $byId[(int) $group['id']] = $group;
            }
        }

        $paths = [];
        foreach ($byId as $group) {
            $names = [];
            $current = $group;
            $guard = 0;
            while ($current !== null && $guard++ < 20) {
                array_unshift($names, $this->string($current['name'] ?? ''));
                $current = is_numeric($current['parent_id'] ?? null) ? ($byId[(int) $current['parent_id']] ?? null) : null;
            }

            $paths[$this->string($group['uuid'] ?? '')] = implode('/', $names);
        }

        return $paths;
    }

    /**
     * @param list<string> $only
     * @param list<string> $warnings
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    private function datasources(string $spaceId, array $only, ?string $entriesDirectory, bool $includeSecrets, array &$warnings): array
    {
        $exportAction = new DatasourceEntriesExportAction($this->client);
        $exported = [];
        $files = [];
        $all = new DatasourceLookup($this->client)->datasources($spaceId);
        $selected = array_values(array_filter($all, fn(array $datasource): bool => $this->string($datasource['slug'] ?? '') !== ''
            && ($only === [] || in_array($this->string($datasource['slug'] ?? ''), $only, true))));
        foreach ($selected as $index => $datasource) {
            $slug = $this->string($datasource['slug'] ?? '');
            $this->report(sprintf('  Datasource %d/%d: %s', $index + 1, count($selected), $slug));

            $definition = ['slug' => $slug, 'name' => $this->string($datasource['name'] ?? $slug)];
            $dimensions = [];
            foreach (DatasourceLookup::dimensions($datasource) as $code => $dimension) {
                $dimensions[$code] = $this->string($dimension['name'] ?? $code);
            }

            if ($dimensions !== []) {
                $definition['dimensions'] = $dimensions;
            }

            $entries = $exportAction->fromDatasource($spaceId, $datasource);
            $rows = $entries->rows;
            if (!$includeSecrets) {
                foreach ($rows as $index => $row) {
                    $name = $row[0] ?? '';
                    if (!$this->isSecretName($name)) {
                        continue;
                    }

                    foreach (array_keys($row) as $column) {
                        if ($column > 0 && $row[$column] !== '') {
                            $rows[$index][$column] = $this->secretPlaceholder;
                            $warnings[] = sprintf('Datasource %s, entry %s: %s replaced with %s.', $slug, $name, $entries->header[$column] ?? 'value', $this->secretPlaceholder);
                        }
                    }
                }
            }

            if ($rows !== [] && $entriesDirectory !== null) {
                $path = rtrim($entriesDirectory, '/') . '/' . $slug . '.csv';
                $files[$path] = DatasourceEntriesCsv::format($entries->header, $rows);
                $definition['entries_file'] = $path;
            } elseif ($rows !== []) {
                $definition['entries'] = $this->inlineEntries($entries->header, $rows);
            }

            $exported[] = $definition;
        }

        if ($only !== []) {
            $missing = array_diff($only, array_map($this->string(...), array_column($exported, 'slug')));
            if ($missing !== []) {
                throw new \RuntimeException('Datasource not found: ' . implode(', ', $missing));
            }
        }

        return [$exported, $files];
    }

    /**
     * @param list<string> $header
     * @param list<list<string>> $rows
     * @return list<array<string, mixed>>
     */
    private function inlineEntries(array $header, array $rows): array
    {
        $entries = [];
        foreach ($rows as $row) {
            $entry = ['name' => $row[0] ?? '', 'value' => $row[1] ?? ''];
            $dimensions = [];
            foreach (array_slice($header, 2, null, true) as $index => $code) {
                if (($row[$index] ?? '') !== '') {
                    $dimensions[$code] = $row[$index];
                }
            }

            if ($dimensions !== []) {
                $entry['dimensions'] = $dimensions;
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @param list<string> $warnings
     * @return list<array<string, mixed>>
     */
    private function roles(string $spaceId, array &$warnings): array
    {
        $roles = new SpaceRoleListAction($this->client)->execute($spaceId)->roles;
        $storyIds = [];
        foreach ($roles as $role) {
            foreach (['allowed_paths', 'blocked_paths'] as $key) {
                foreach (is_array($role[$key] ?? null) ? $role[$key] : [] as $id) {
                    if (is_numeric($id)) {
                        $storyIds[(int) $id] = true;
                    }
                }
            }
        }

        $slugs = $this->storySlugs($spaceId, array_keys($storyIds));
        $exported = [];
        foreach ($roles as $role) {
            $name = $this->string($role['role'] ?? '');
            $definition = ['name' => $name];
            $definition += $this->nonEmpty(['subtitle' => $role['subtitle'] ?? null]);
            foreach (['permissions', 'allowed_languages'] as $key) {
                $values = is_array($role[$key] ?? null) ? array_values(array_map($this->string(...), $role[$key])) : [];
                if ($values !== []) {
                    $definition[$key] = $values;
                }
            }

            foreach (['allowed_paths', 'blocked_paths'] as $key) {
                $paths = [];
                foreach (is_array($role[$key] ?? null) ? $role[$key] : [] as $id) {
                    $paths[] = $slugs[(int) (is_numeric($id) ? $id : 0)] ?? (int) (is_numeric($id) ? $id : 0);
                }

                if ($paths !== []) {
                    $definition[$key] = $paths;
                }
            }

            $unsupported = array_values(array_filter(
                self::UNSUPPORTED_ROLE_PROPERTIES,
                static fn(string $property): bool => !empty($role[$property] ?? null),
            ));
            if ($unsupported !== []) {
                $warnings[] = sprintf('Role %s: not exported: %s.', $name, implode(', ', $unsupported));
            }

            $exported[] = $definition;
        }

        return $exported;
    }

    /**
     * Full slugs of stories and folders by ID (folders without trailing slash).
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function storySlugs(string $spaceId, array $ids): array
    {
        $slugs = [];
        foreach (array_chunk($ids, 100) as $chunk) {
            $response = new ManagementApi($this->client)->get(
                sprintf('spaces/%s/stories', $spaceId),
                ['by_ids' => implode(',', $chunk), 'per_page' => 100],
            );
            if (!$response->isOk()) {
                throw new \RuntimeException('Failed to fetch stories: ' . ApiError::message($response));
            }

            $data = $response->toArray();
            foreach (is_array($data['stories'] ?? null) ? $data['stories'] : [] as $story) {
                if (is_array($story) && is_numeric($story['id'] ?? null) && is_string($story['full_slug'] ?? null)) {
                    $slugs[(int) $story['id']] = $story['full_slug'];
                }
            }
        }

        return $slugs;
    }

    /**
     * @param list<string> $warnings
     * @return list<array<string, mixed>>
     */
    private function workflows(string $spaceId, array &$warnings): array
    {
        $lookup = new WorkflowLookup($this->client);
        $roleNames = [];
        foreach (new SpaceRoleListAction($this->client)->execute($spaceId)->roles as $role) {
            if (is_numeric($role['id'] ?? null)) {
                $roleNames[(int) $role['id']] = $this->string($role['role'] ?? '');
            }
        }

        $exported = [];
        $workflows = $lookup->workflows($spaceId);
        foreach ($workflows as $index => $workflow) {
            $name = $this->string($workflow['name'] ?? '');
            $this->report(sprintf('  Workflow %d/%d: %s', $index + 1, count($workflows), $name));
            $definition = ['name' => $name];
            $contentTypes = is_array($workflow['content_types'] ?? null) ? array_values(array_map($this->string(...), $workflow['content_types'])) : [];
            if ($contentTypes !== []) {
                $definition['content_types'] = $contentTypes;
            }

            $stages = $lookup->stages($spaceId, is_numeric($workflow['id'] ?? null) ? (int) $workflow['id'] : 0);
            usort($stages, static fn(array $a, array $b): int => (is_numeric($a['position'] ?? null) ? (int) $a['position'] : 0) <=> (is_numeric($b['position'] ?? null) ? (int) $b['position'] : 0));
            $stageNames = [];
            foreach ($stages as $stage) {
                if (is_numeric($stage['id'] ?? null)) {
                    $stageNames[(int) $stage['id']] = $this->string($stage['name'] ?? '');
                }
            }

            $definition['stages'] = array_map(
                fn(array $stage): array => $this->stage($name, $stage, $stageNames, $roleNames, $warnings),
                $stages,
            );
            $exported[] = $definition;
        }

        return $exported;
    }

    /**
     * @param array<string, mixed> $stage
     * @param array<int, string> $stageNames
     * @param array<int, string> $roleNames
     * @param list<string> $warnings
     * @return array<string, mixed>
     */
    private function stage(string $workflow, array $stage, array $stageNames, array $roleNames, array &$warnings): array
    {
        $name = $this->string($stage['name'] ?? '');
        $definition = ['name' => $name];
        $definition += $this->nonEmpty(['color' => $stage['color'] ?? null]);
        foreach (['is_default' => 'default', 'allow_publish' => 'allow_publish', 'allow_admin_publish' => 'allow_admin_publish', 'allow_admin_change' => 'allow_admin_change', 'allow_editor_change' => 'allow_editor_change'] as $source => $target) {
            if (($stage[$source] ?? false) === true) {
                $definition[$target] = true;
            }
        }

        $definition['roles'] = ($stage['allow_all_users'] ?? true) === true
            ? 'all'
            : $this->names(is_array($stage['space_role_ids'] ?? null) ? $stage['space_role_ids'] : [], $roleNames, sprintf('Workflow %s, stage %s: role', $workflow, $name), $warnings);
        $definition['next'] = ($stage['allow_all_stages'] ?? true) === true
            ? 'all'
            : $this->names(is_array($stage['workflow_stage_ids'] ?? null) ? $stage['workflow_stage_ids'] : [], $stageNames, sprintf('Workflow %s, stage %s: next stage', $workflow, $name), $warnings);

        $afterPublish = is_numeric($stage['after_publish_id'] ?? null) ? ($stageNames[(int) $stage['after_publish_id']] ?? null) : null;
        if ($afterPublish !== null) {
            $definition['after_publish'] = $afterPublish;
        }

        if (!empty($stage['user_ids'] ?? null)) {
            $warnings[] = sprintf('Workflow %s, stage %s: not exported: user_ids (users are not part of setup configs).', $workflow, $name);
        }

        return $definition;
    }

    /**
     * @param array<mixed> $ids
     * @param array<int, string> $names
     * @param list<string> $warnings
     * @return list<string>
     */
    private function names(array $ids, array $names, string $context, array &$warnings): array
    {
        $resolved = [];
        foreach ($ids as $id) {
            $id = is_numeric($id) ? (int) $id : 0;
            if (isset($names[$id])) {
                $resolved[] = $names[$id];
            } else {
                $warnings[] = sprintf('%s %d not found, not exported.', $context, $id);
            }
        }

        return $resolved;
    }

    /**
     * Replace the values of secret options and properties of component fields with the
     * secret placeholder.
     *
     * @param list<array<string, mixed>> $components
     * @param list<string> $warnings
     * @return list<array<string, mixed>>
     */
    private function maskComponentSecrets(array $components, array &$warnings): array
    {
        foreach ($components as $componentIndex => $component) {
            $componentName = $this->string($component['name'] ?? '');
            $fields = is_array($component['fields'] ?? null) ? $component['fields'] : [];
            foreach ($fields as $fieldIndex => $field) {
                if (!is_array($field)) {
                    continue;
                }

                $fieldName = $this->string($field['field'] ?? '');
                $prefix = sprintf('Component %s, field %s', $componentName, $fieldName);
                foreach (['options', 'properties'] as $key) {
                    if (is_array($field[$key] ?? null)) {
                        $field[$key] = $this->maskSecrets($field[$key], $prefix, $warnings);
                    }
                }

                $fields[$fieldIndex] = $field;
            }

            if ($fields !== []) {
                $components[$componentIndex]['fields'] = $fields;
            }
        }

        return $components;
    }

    /**
     * Mask values whose key, or whose sibling `name` in a name/value pair, is a secret name.
     *
     * @param array<mixed> $values
     * @param list<string> $warnings
     * @return array<mixed>
     */
    private function maskSecrets(array $values, string $context, array &$warnings): array
    {
        if (is_string($values['name'] ?? null) && array_key_exists('value', $values) && $this->isSecretName($values['name'])) {
            if (is_scalar($values['value']) && (string) $values['value'] !== '') {
                $values['value'] = $this->secretPlaceholder;
                $warnings[] = sprintf('%s: %s replaced with %s.', $context, $values['name'], $this->secretPlaceholder);
            }

            return $values;
        }

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->maskSecrets($value, $context, $warnings);
            } elseif (is_string($key) && is_scalar($value) && (string) $value !== '' && !is_bool($value) && $this->isSecretName($key)) {
                $values[$key] = $this->secretPlaceholder;
                $warnings[] = sprintf('%s: %s replaced with %s.', $context, $key, $this->secretPlaceholder);
            }
        }

        return $values;
    }

    /**
     * Whether a name contains one of the secret names, ignoring case, `_`, and `-`
     * (so `access_token` also matches `accessToken` and `storyblokAccessToken`).
     */
    private function isSecretName(string $name): bool
    {
        $normalized = $this->normalizeName($name);
        foreach ($this->secretNames as $secretName) {
            $needle = $this->normalizeName($secretName);
            if ($needle !== '' && str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeName(string $name): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $name));
    }

    private function report(string $message): void
    {
        if ($this->progress instanceof \Closure) {
            ($this->progress)($message);
        }
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function nonEmpty(array $values): array
    {
        return array_filter($values, static fn(mixed $value): bool => $value !== null && $value !== '');
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
