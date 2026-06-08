<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

use Blokctl\Action\AppProvision\AppProvisionInstallAction;
use Blokctl\Action\Component\ComponentFieldAddAction;
use Blokctl\Action\Component\ComponentFieldAddResult;
use Blokctl\Action\Folder\FolderCreateAction;
use Blokctl\Action\Space\SpaceDemoRemoveAction;
use Blokctl\Action\SpacePreview\SpacePreviewSetAction;
use Blokctl\Action\Story\StoriesTagsAssignAction;
use Blokctl\Action\Story\StoriesWorkflowAssignAction;
use Storyblok\ManagementApi\Data\AppProvision;
use Storyblok\ManagementApi\Data\Component;
use Storyblok\ManagementApi\Data\Space;
use Storyblok\ManagementApi\Data\SpaceEnvironment;
use Storyblok\ManagementApi\Data\StoryBaseData;
use Storyblok\ManagementApi\Endpoints\AppProvisionApi;
use Storyblok\ManagementApi\Endpoints\ComponentApi;
use Storyblok\ManagementApi\Endpoints\ManagementApi;
use Storyblok\ManagementApi\Endpoints\SpaceApi;
use Storyblok\ManagementApi\Endpoints\StoryApi;
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\QueryParameters\PaginationParams;
use Storyblok\ManagementApi\QueryParameters\StoriesParams;

final readonly class SpaceSetupProvisioner
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public function run(
        string $spaceId,
        array $config,
        bool $dryRun,
        bool $continueOnError,
        string $mode,
    ): SpaceSetupReporter {
        $reporter = new SpaceSetupReporter($dryRun);
        $reporter->start($spaceId, $mode);

        try {
            $this->provisionPreview($reporter, $spaceId, $config, $dryRun, $continueOnError);
            $this->provisionDemoMode($reporter, $spaceId, $config, $dryRun, $continueOnError);
            $this->provisionWorkflow($reporter, $spaceId, $config, $dryRun, $continueOnError);
            $this->provisionFolders($reporter, $spaceId, $config, $dryRun, $continueOnError);
            $this->provisionStoryMoves($reporter, $spaceId, $config, $dryRun, $continueOnError);
            $this->provisionApps($reporter, $spaceId, $config, $dryRun, $continueOnError);
            $this->provisionDimensions($reporter, $spaceId, $config, $dryRun, $continueOnError);
            $this->provisionComponentFields($reporter, $spaceId, $config, $dryRun, $continueOnError);
            $this->provisionTags($reporter, $spaceId, $config, $dryRun, $continueOnError);
        } catch (\Exception $exception) {
            $reporter->finish();
            throw new SpaceSetupProvisioningException($reporter, $exception);
        }

        $reporter->finish();

        return $reporter;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function provisionFolders(
        SpaceSetupReporter $reporter,
        string $spaceId,
        array $config,
        bool $dryRun,
        bool $continueOnError,
    ): void {
        $folders = $this->arrayValue($config['folders'] ?? []);
        foreach ($this->listValue($folders['ensure'] ?? []) as $folder) {
            if (!is_array($folder)) {
                continue;
            }

            $name = $this->stringValue($folder['name'] ?? '');
            $slug = $this->stringValue($folder['slug'] ?? '');
            $parentSlug = $this->nullableStringValue($folder['parent_slug'] ?? null);
            $label = 'Ensure folder: ' . $slug;

            $reporter->run($label, SpaceSetupOperationStatus::Created, $continueOnError, function () use ($spaceId, $name, $slug, $parentSlug, $dryRun, $label): SpaceSetupOperationResult|null {
                if ($name === '' || $slug === '') {
                    throw new \RuntimeException('Folder entries require name and slug.');
                }

                if ($dryRun) {
                    return new SpaceSetupOperationResult(
                        SpaceSetupOperationStatus::Created,
                        $label,
                        $parentSlug === null ? 'Parent: root' : 'Parent: ' . $parentSlug,
                    );
                }

                $existing = $this->findFolderBySlug($spaceId, $slug);
                if ($existing !== null) {
                    return new SpaceSetupOperationResult(
                        SpaceSetupOperationStatus::Skipped,
                        $label,
                        'Folder already exists.',
                    );
                }

                $parentId = $parentSlug === null ? 0 : $this->requireFolderId($spaceId, $parentSlug);
                new FolderCreateAction($this->client)->execute(
                    $spaceId,
                    $name,
                    $parentId,
                    $this->folderLocalSlug($slug),
                );

                return null;
            });
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function provisionStoryMoves(
        SpaceSetupReporter $reporter,
        string $spaceId,
        array $config,
        bool $dryRun,
        bool $continueOnError,
    ): void {
        $stories = $this->arrayValue($config['stories'] ?? []);
        foreach ($this->listValue($stories['move'] ?? []) as $move) {
            if (!is_array($move)) {
                continue;
            }

            $targetSlug = $this->stringValue($move['to_folder'] ?? '');
            $selector = $this->arrayValue($move['select'] ?? []);
            $label = 'Move selected root content to: ' . $targetSlug;
            $reporter->run($label, SpaceSetupOperationStatus::Updated, $continueOnError, function () use ($spaceId, $targetSlug, $selector, $dryRun, $label): SpaceSetupOperationResult {
                if ($targetSlug === '') {
                    throw new \RuntimeException('Story move entries require to_folder.');
                }

                if ($this->stringValue($selector['parent'] ?? '') !== 'root') {
                    throw new \RuntimeException('Story move selectors currently require parent: root.');
                }

                if ($dryRun) {
                    return new SpaceSetupOperationResult(
                        SpaceSetupOperationStatus::Updated,
                        $label,
                        'Matching root-level content will be moved.',
                    );
                }

                $targetId = $this->requireFolderId($spaceId, $targetSlug);
                $items = $this->matchingRootItems($spaceId, $targetId, $selector);
                if ($items === []) {
                    return new SpaceSetupOperationResult(
                        SpaceSetupOperationStatus::Skipped,
                        $label,
                        'No matching root-level content to move.',
                    );
                }

                $api = new ManagementApi($this->client);
                foreach ($items as $item) {
                    $response = $api->put(sprintf('spaces/%s/stories/%s', $spaceId, $item['id']), [
                        'story' => ['parent_id' => $targetId],
                    ]);
                    if (!$response->isOk()) {
                        throw new \RuntimeException('Failed to move "' . $item['slug'] . '": ' . $response->getErrorMessage());
                    }
                }

                return new SpaceSetupOperationResult(
                    SpaceSetupOperationStatus::Updated,
                    $label,
                    count($items) . ' item(s) moved.',
                );
            });
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function provisionPreview(
        SpaceSetupReporter $reporter,
        string $spaceId,
        array $config,
        bool $dryRun,
        bool $continueOnError,
    ): void {
        if (!$this->sectionEnabled($config['preview'] ?? null)) {
            return;
        }

        $preview = $this->arrayValue($config['preview'] ?? []);
        $defaultUrl = $this->stringValue($preview['default'] ?? '');
        $environmentConfigs = $this->listValue($preview['environments'] ?? []);
        $reporter->run(
            'Configure preview URLs',
            SpaceSetupOperationStatus::Updated,
            $continueOnError,
            function () use ($spaceId, $defaultUrl, $environmentConfigs, $dryRun): SpaceSetupOperationResult {
                if ($defaultUrl === '') {
                    throw new \RuntimeException('preview.default is required when preview is enabled.');
                }

                $configuredEnvironments = [];
                foreach ($environmentConfigs as $environment) {
                    if (!is_array($environment)) {
                        continue;
                    }

                    $name = $this->stringValue($environment['name'] ?? '');
                    $url = $this->stringValue($environment['url'] ?? '');
                    if ($name === '' || $url === '') {
                        throw new \RuntimeException('Each preview environment requires name and url.');
                    }

                    $configuredEnvironments[] = new SpaceEnvironment($name, $url);
                }

                if (!$dryRun) {
                    $action = new SpacePreviewSetAction($this->client);
                    $preflight = $action->preflight($spaceId);
                    $environments = $this->mergeEnvironments(
                        $preflight->space->environments()->toArray(),
                        $configuredEnvironments,
                    );

                    if (
                        $preflight->space->domain() === $defaultUrl
                        && $environments === $preflight->space->environments()->toArray()
                    ) {
                        return new SpaceSetupOperationResult(
                            SpaceSetupOperationStatus::Skipped,
                            'Configure preview URLs',
                            'Preview URLs already match.',
                        );
                    }

                    $action->execute(
                        $spaceId,
                        $preflight,
                        $defaultUrl,
                        array_map(
                            SpaceEnvironment::make(...),
                            $environments,
                        ),
                    );
                }

                return new SpaceSetupOperationResult(
                    SpaceSetupOperationStatus::Updated,
                    'Configure preview URLs',
                    $defaultUrl . ' (' . count($configuredEnvironments) . ' configured environments)',
                );
            },
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function provisionDemoMode(
        SpaceSetupReporter $reporter,
        string $spaceId,
        array $config,
        bool $dryRun,
        bool $continueOnError,
    ): void {
        if (!$this->boolValue($this->arrayValue($config['demo_mode'] ?? [])['remove'] ?? false)) {
            return;
        }

        $reporter->run('Remove demo mode', SpaceSetupOperationStatus::Removed, $continueOnError, function () use ($spaceId, $dryRun): ?SpaceSetupOperationResult {
            if ($dryRun) {
                return null;
            }

            $action = new SpaceDemoRemoveAction($this->client);
            $preflight = $action->preflight($spaceId);
            if (!$preflight->isDemo) {
                return new SpaceSetupOperationResult(
                    SpaceSetupOperationStatus::Skipped,
                    'Remove demo mode',
                    'Space is not in demo mode.',
                );
            }

            $action->execute($spaceId, $preflight);
            return null;
        });
    }

    /**
     * @param array<string, mixed> $config
     */
    private function provisionWorkflow(
        SpaceSetupReporter $reporter,
        string $spaceId,
        array $config,
        bool $dryRun,
        bool $continueOnError,
    ): void {
        $workflow = $this->arrayValue($config['workflow'] ?? []);
        if (!$this->boolValue($workflow['assign_unstaged'] ?? false)) {
            return;
        }

        $reporter->run('Assign workflow stages', SpaceSetupOperationStatus::Updated, $continueOnError, function () use ($spaceId, $workflow, $dryRun): ?SpaceSetupOperationResult {
            $stageId = $this->nullableIntValue($workflow['stage_id'] ?? null);

            if ($dryRun) {
                return null;
            }

            $action = new StoriesWorkflowAssignAction($this->client);
            $preflight = $action->preflight($spaceId);
            if ($preflight->countWithoutStage === 0) {
                return new SpaceSetupOperationResult(
                    SpaceSetupOperationStatus::Skipped,
                    'Assign workflow stages',
                    'All stories already have workflow stages.',
                );
            }

            $stageId ??= $this->nullableIntValue($preflight->defaultStageId);
            if ($stageId === null) {
                throw new \RuntimeException('workflow.stage_id is required because no default workflow stage could be resolved.');
            }

            $result = $action->execute($spaceId, $preflight, $stageId);
            if ($result['errors'] !== []) {
                throw new \RuntimeException(implode(' | ', $result['errors']));
            }

            return new SpaceSetupOperationResult(
                SpaceSetupOperationStatus::Updated,
                'Assign workflow stages',
                count($result['assigned']) . ' stories assigned.',
            );
        });
    }

    /**
     * @param array<string, mixed> $config
     */
    private function provisionApps(
        SpaceSetupReporter $reporter,
        string $spaceId,
        array $config,
        bool $dryRun,
        bool $continueOnError,
    ): void {
        $apps = $this->arrayValue($config['apps'] ?? []);
        $install = $this->listValue($apps['install'] ?? []);
        $installedSlugs = [];
        $installedIds = [];
        if (!$dryRun && $install !== []) {
            $provisions = new AppProvisionApi($this->client, $spaceId)->page()->data();
            foreach ($provisions as $provision) {
                if ($provision instanceof AppProvision) {
                    $installedSlugs[] = $provision->slug();
                    $installedIds[] = $provision->appId();
                }
            }
        }

        foreach ($install as $app) {
            $slug = is_array($app) ? $this->nullableStringValue($app['slug'] ?? null) : $this->nullableStringValue($app);
            $id = is_array($app) ? $this->nullableStringValue($app['id'] ?? null) : null;
            $identifier = $slug ?? $id ?? '';
            $reporter->run('Install app: ' . $identifier, SpaceSetupOperationStatus::Installed, $continueOnError || $this->boolValue($apps['continue_on_error'] ?? false), function () use ($spaceId, $slug, $id, $identifier, $dryRun, $installedSlugs, $installedIds): ?SpaceSetupOperationResult {
                if ($identifier === '') {
                    throw new \RuntimeException('App installation entries require a slug or id.');
                }

                if (!$dryRun) {
                    if (
                        ($slug !== null && in_array($slug, $installedSlugs, true))
                        || ($id !== null && in_array($id, $installedIds, true))
                    ) {
                        return new SpaceSetupOperationResult(
                            SpaceSetupOperationStatus::Skipped,
                            'Install app: ' . $identifier,
                            'App is already installed.',
                        );
                    }

                    $action = new AppProvisionInstallAction($this->client);
                    $action->execute($spaceId, $this->resolveAppId($action, $spaceId, $slug, $id));
                }

                return null;
            });
        }
    }

    private function resolveAppId(
        AppProvisionInstallAction $action,
        string $spaceId,
        string|null $slug,
        string|null $fallbackId,
    ): string {
        if ($slug === null) {
            return $fallbackId ?? throw new \RuntimeException('App installation entries require a slug or id.');
        }

        try {
            return $action->resolveBySlug($spaceId, $slug);
        } catch (\RuntimeException $runtimeException) {
            if (
                $fallbackId !== null
                && str_starts_with($runtimeException->getMessage(), 'No app found with slug:')
            ) {
                return $fallbackId;
            }

            throw $runtimeException;
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function provisionDimensions(
        SpaceSetupReporter $reporter,
        string $spaceId,
        array $config,
        bool $dryRun,
        bool $continueOnError,
    ): void {
        if (!$this->sectionEnabled($config['dimensions'] ?? null)) {
            return;
        }

        $dimensions = $this->arrayValue($config['dimensions'] ?? []);
        $folderConfigs = $this->listValue($dimensions['folders'] ?? []);
        $reporter->run('Configure Dimensions folders', SpaceSetupOperationStatus::Updated, $continueOnError, function () use ($spaceId, $folderConfigs, $dryRun): SpaceSetupOperationResult {
            if ($dryRun) {
                return new SpaceSetupOperationResult(
                    SpaceSetupOperationStatus::Updated,
                    'Configure Dimensions folders',
                    count($folderConfigs) . ' configured folder(s).',
                );
            }

            $spaceApi = new SpaceApi($this->client);
            $space = $spaceApi->get($spaceId)->data()->toArray();
            $existingIds = $this->intListValue($space['dimensions_app_folder_ids'] ?? []);
            $existingFolders = $this->dimensionFolderListValue($space['dimensions_app_folders'] ?? []);
            $mergedById = [];
            foreach ($existingFolders as $existingFolder) {
                $mergedById[$existingFolder['folder_id']] = $existingFolder;
            }

            foreach ($folderConfigs as $folderConfig) {
                if (!is_array($folderConfig)) {
                    continue;
                }

                $slug = $this->stringValue($folderConfig['slug'] ?? '');
                if ($slug === '') {
                    throw new \RuntimeException('Dimensions folder entries require slug.');
                }

                $folderId = $this->requireFolderId($spaceId, $slug);
                $configured = $mergedById[$folderId] ?? [
                    'folder_id' => $folderId,
                    'ai_translation_code' => '',
                ];
                if (array_key_exists('ai_translation_code', $folderConfig)) {
                    $configured['ai_translation_code'] = $this->stringValue($folderConfig['ai_translation_code']);
                }

                $mergedById[$folderId] = $configured;
                if (!in_array($folderId, $existingIds, true)) {
                    $existingIds[] = $folderId;
                }
            }

            $mergedFolders = array_values($mergedById);
            if (
                $existingIds === $this->intListValue($space['dimensions_app_folder_ids'] ?? [])
                && $mergedFolders === $existingFolders
            ) {
                return new SpaceSetupOperationResult(
                    SpaceSetupOperationStatus::Skipped,
                    'Configure Dimensions folders',
                    'Dimensions folders already match.',
                );
            }

            $response = $spaceApi->update($spaceId, Space::forUpdate([
                'dimensions_app_folder_ids' => $existingIds,
                'dimensions_app_folders' => $mergedFolders,
            ]));
            if (!$response->isOk()) {
                throw new \RuntimeException('Failed to configure Dimensions folders: ' . $response->getErrorMessage());
            }

            return new SpaceSetupOperationResult(
                SpaceSetupOperationStatus::Updated,
                'Configure Dimensions folders',
                count($folderConfigs) . ' configured folder(s).',
            );
        });
    }

    /**
     * @param array<string, mixed> $config
     */
    private function provisionComponentFields(
        SpaceSetupReporter $reporter,
        string $spaceId,
        array $config,
        bool $dryRun,
        bool $continueOnError,
    ): void {
        $components = $this->arrayValue($config['components'] ?? []);
        foreach ($this->listValue($components['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }

            $componentName = $this->stringValue($field['component'] ?? '');
            $fieldName = $this->stringValue($field['field'] ?? '');
            $reporter->run('Add component field: ' . $componentName . '.' . $fieldName, SpaceSetupOperationStatus::Created, $continueOnError, function () use ($spaceId, $field, $componentName, $fieldName, $dryRun): ?SpaceSetupOperationResult {
                $type = $this->stringValue($field['type'] ?? '');
                $tab = $this->stringValue($field['tab'] ?? 'General');
                if ($componentName === '' || $fieldName === '' || $type === '') {
                    throw new \RuntimeException('Component field entries require component, field, and type.');
                }

                if ($dryRun) {
                    return null;
                }

                return $this->reconcileComponentField($spaceId, $componentName, $fieldName, $type, $tab, $field);
            });
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function provisionTags(
        SpaceSetupReporter $reporter,
        string $spaceId,
        array $config,
        bool $dryRun,
        bool $continueOnError,
    ): void {
        foreach ($this->listValue($config['tags'] ?? []) as $tagGroup) {
            if (!is_array($tagGroup)) {
                continue;
            }

            $tags = $this->stringListValue($tagGroup['tags'] ?? []);
            $stories = $this->arrayValue($tagGroup['stories'] ?? []);
            $storyIds = $this->stringListValue($stories['ids'] ?? []);
            $storySlugs = $this->stringListValue($stories['slugs'] ?? []);
            $label = 'Assign tags: ' . implode(', ', $tags);
            $reporter->run($label, SpaceSetupOperationStatus::Updated, $continueOnError, function () use ($spaceId, $tags, $storyIds, $storySlugs, $dryRun, $label): SpaceSetupOperationResult {
                if ($tags === []) {
                    throw new \RuntimeException('Tag assignment entries require at least one tag.');
                }

                if ($storyIds === [] && $storySlugs === []) {
                    throw new \RuntimeException('Tag assignment entries require stories.ids or stories.slugs.');
                }

                if ($dryRun) {
                    return new SpaceSetupOperationResult(
                        SpaceSetupOperationStatus::Updated,
                        $label,
                        'Stories: ' . implode(', ', [...$storyIds, ...$storySlugs]),
                    );
                }

                $result = new StoriesTagsAssignAction($this->client)->execute($spaceId, $storyIds, $storySlugs, $tags, merge: true);
                if ($result->errors !== []) {
                    throw new \RuntimeException(implode(' | ', $result->errors));
                }

                if ($result->tagged === []) {
                    return new SpaceSetupOperationResult(
                        SpaceSetupOperationStatus::Skipped,
                        $label,
                        count($result->skipped) . ' stories already have the requested tags.',
                    );
                }

                return new SpaceSetupOperationResult(
                    SpaceSetupOperationStatus::Updated,
                    $label,
                    count($result->tagged) . ' stories tagged; ' . count($result->skipped) . ' unchanged.',
                );
            });
        }
    }

    /**
     * @param array<int|string, mixed> $existing
     * @param SpaceEnvironment[] $configured
     *
     * @return array<int, array<string, mixed>>
     */
    private function mergeEnvironments(array $existing, array $configured): array
    {
        $merged = [];
        $indexesByName = [];

        foreach ($existing as $environment) {
            if (!is_array($environment)) {
                continue;
            }

            $name = $this->stringValue($environment['name'] ?? '');
            if ($name !== '') {
                $indexesByName[$name] = count($merged);
            }

            $merged[] = $environment;
        }

        foreach ($configured as $environment) {
            $data = $environment->toArray();
            $name = $environment->name();
            if (array_key_exists($name, $indexesByName)) {
                $merged[$indexesByName[$name]] = $data;
                continue;
            }

            $indexesByName[$name] = count($merged);
            $merged[] = $data;
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function reconcileComponentField(
        string $spaceId,
        string $componentName,
        string $fieldName,
        string $type,
        string $tab,
        array $field,
    ): SpaceSetupOperationResult {
        $componentApi = new ComponentApi($this->client, $spaceId);
        $component = $this->findComponent($componentApi, $componentName);
        $schema = $component->getSchema();
        $label = 'Add component field: ' . $componentName . '.' . $fieldName;

        if (!array_key_exists($fieldName, $schema)) {
            new ComponentFieldAddAction($this->client)->execute(
                spaceId: $spaceId,
                preflight: new ComponentFieldAddResult($component, $schema),
                fieldName: $fieldName,
                type: $type,
                tabName: $tab,
                fieldType: $this->nullableStringValue($field['field_type'] ?? $field['fieldType'] ?? null),
                pos: $this->nullableIntValue($field['pos'] ?? null),
                displayName: $this->nullableStringValue($field['display_name'] ?? $field['displayName'] ?? null),
                required: $this->boolValue($field['required'] ?? false),
                translatable: $this->boolValue($field['translatable'] ?? false),
            );

            return new SpaceSetupOperationResult(SpaceSetupOperationStatus::Created, $label);
        }

        $existingField = $schema[$fieldName];
        $changed = $this->applyDeclaredFieldProperties($existingField, $field, $type);
        $schema[$fieldName] = $existingField;

        if (array_key_exists('tab', $field)) {
            $changed = $this->assignFieldToTab($schema, $fieldName, $tab, $component) || $changed;
        }

        if (!$changed) {
            return new SpaceSetupOperationResult(
                SpaceSetupOperationStatus::Skipped,
                $label,
                'Component field already matches.',
            );
        }

        $component->setSchema($schema);
        $componentApi->update($component->id(), $component);

        return new SpaceSetupOperationResult(
            SpaceSetupOperationStatus::Updated,
            $label,
            'Updated explicitly configured field properties.',
        );
    }

    private function findComponent(ComponentApi $componentApi, string $componentName): Component
    {
        $components = $componentApi->all()->data();
        foreach ($components as $component) {
            if ($component instanceof Component && $component->name() === $componentName) {
                return $componentApi->get($component->id())->data();
            }
        }

        throw new \RuntimeException('Component "' . $componentName . '" not found.');
    }

    /**
     * @param array<mixed> $existing
     * @param array<string, mixed> $declared
     */
    private function applyDeclaredFieldProperties(array &$existing, array $declared, string $type): bool
    {
        $changed = $this->setWhenDifferent($existing, 'type', $type);
        $aliases = [
            'field_type' => ['field_type', 'fieldType'],
            'display_name' => ['display_name', 'displayName'],
            'pos' => ['pos'],
            'required' => ['required'],
            'translatable' => ['translatable'],
        ];

        foreach ($aliases as $target => $sourceKeys) {
            foreach ($sourceKeys as $sourceKey) {
                if (!array_key_exists($sourceKey, $declared)) {
                    continue;
                }

                $value = $declared[$sourceKey];
                if ($target === 'pos') {
                    $value = $this->nullableIntValue($value);
                } elseif (in_array($target, ['required', 'translatable'], true)) {
                    $value = $this->boolValue($value);
                } else {
                    $value = $this->nullableStringValue($value);
                }

                $changed = $this->setWhenDifferent($existing, $target, $value) || $changed;
                break;
            }
        }

        return $changed;
    }

    /**
     * @param array<mixed> $values
     */
    private function setWhenDifferent(array &$values, string $key, mixed $value): bool
    {
        if (array_key_exists($key, $values) && $values[$key] === $value) {
            return false;
        }

        $values[$key] = $value;
        return true;
    }

    /**
     * @param array<string, array<mixed>> $schema
     */
    private function assignFieldToTab(array &$schema, string $fieldName, string $tabName, Component $component): bool
    {
        if ($component->getFieldTab($fieldName) === $tabName) {
            return false;
        }

        $targetTabKey = null;
        foreach ($schema as $key => &$entry) {
            if (($entry['type'] ?? '') !== 'tab') {
                continue;
            }

            $keys = is_array($entry['keys'] ?? null) ? $entry['keys'] : [];
            $entry['keys'] = array_values(array_filter(
                $keys,
                static fn(mixed $key): bool => $key !== $fieldName,
            ));

            if (($entry['display_name'] ?? '') === $tabName) {
                $targetTabKey = $key;
            }
        }

        unset($entry);

        if ($targetTabKey === null) {
            $targetTabKey = 'tab-' . bin2hex(random_bytes(16));
            $schema[$targetTabKey] = [
                'display_name' => $tabName,
                'keys' => [],
                'pos' => $component->maxPos() + 1,
                'type' => 'tab',
            ];
        }

        $rawKeys = $schema[$targetTabKey]['keys'] ?? [];
        $keys = is_array($rawKeys)
            ? array_values(array_filter($rawKeys, is_string(...)))
            : [];
        $keys[] = $fieldName;
        $schema[$targetTabKey]['keys'] = array_values(array_unique($keys));

        return true;
    }

    /**
     * @return array{id: int, slug: string, full_slug: string, parent_id: int|null, is_folder: bool}|null
     */
    private function findFolderBySlug(string $spaceId, string $slug): array|null
    {
        $folders = new StoryApi($this->client, $spaceId)
            ->page(new StoriesParams(folderOnly: true, withSlug: $slug))
            ->data();
        $matches = [];
        foreach ($folders as $folder) {
            $folder = $this->storyCollectionItemValue($folder);
            if ($folder === null) {
                continue;
            }

            $fullSlug = $this->stringValue($folder['full_slug'] ?? '');
            if ($fullSlug !== $slug && ($fullSlug !== '' || $this->stringValue($folder['slug'] ?? '') !== $slug)) {
                continue;
            }

            $matches[] = [
                'id' => $this->intValue($folder['id'] ?? null),
                'slug' => $this->stringValue($folder['slug'] ?? ''),
                'full_slug' => $fullSlug,
                'parent_id' => $this->nullableIntValue($folder['parent_id'] ?? null),
                'is_folder' => true,
            ];
        }

        if (count($matches) > 1) {
            throw new \RuntimeException('Multiple folders found with slug: ' . $slug);
        }

        return $matches[0] ?? null;
    }

    private function requireFolderId(string $spaceId, string $slug): int
    {
        $folder = $this->findFolderBySlug($spaceId, $slug);
        if ($folder === null) {
            throw new \RuntimeException('Folder not found with slug: ' . $slug);
        }

        return $folder['id'];
    }

    /**
     * @param array<string, mixed> $selector
     *
     * @return array<int, array{id: string, slug: string}>
     */
    private function matchingRootItems(string $spaceId, int $targetId, array $selector): array
    {
        $includeFolders = $this->boolValue($selector['include_folders'] ?? false);
        $includeSlugs = $this->stringListValue($selector['include_slugs'] ?? []);
        $excludeSlugs = $this->stringListValue($selector['exclude_slugs'] ?? []);
        $matches = [];
        $page = 1;

        do {
            $items = new StoryApi($this->client, $spaceId)
                ->page(new StoriesParams(), page: new PaginationParams($page, 100))
                ->data();
            foreach ($items as $item) {
                $item = $this->storyCollectionItemValue($item);
                if ($item === null) {
                    continue;
                }

                $parentId = $item['parent_id'] ?? null;
                $slug = $this->stringValue($item['slug'] ?? '');
                $isFolder = $this->boolValue($item['is_folder'] ?? false);
                if ($parentId !== 0 && $parentId !== null) {
                    continue;
                }

                if ($this->intValue($item['id'] ?? null) === $targetId) {
                    continue;
                }

                if (!$includeFolders && $isFolder) {
                    continue;
                }

                if ($includeSlugs !== [] && !in_array($slug, $includeSlugs, true)) {
                    continue;
                }

                if (in_array($slug, $excludeSlugs, true)) {
                    continue;
                }

                $matches[] = [
                    'id' => $this->stringValue($item['id'] ?? ''),
                    'slug' => $slug,
                ];
            }

            ++$page;
        } while (count($items) === 100);

        return $matches;
    }

    private function folderLocalSlug(string $fullSlug): string
    {
        $segments = explode('/', trim($fullSlug, '/'));
        return end($segments);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function storyCollectionItemValue(mixed $item): array|null
    {
        if ($item instanceof StoryBaseData) {
            return [
                'id' => $item->id(),
                'slug' => $item->slug(),
                'full_slug' => $item->fullSlug(),
                'parent_id' => $item->parentId(),
                'is_folder' => $item->isFolder(),
            ];
        }

        if (!is_array($item)) {
            return null;
        }

        /** @var array<string, mixed> $item */
        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @return mixed[]
     */
    private function listValue(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @return string[]
     */
    private function stringListValue(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }

        $items = [];
        foreach ($this->listValue($value) as $item) {
            if (is_scalar($item) && (string) $item !== '') {
                $items[] = (string) $item;
            }
        }

        return $items;
    }

    /**
     * @return int[]
     */
    private function intListValue(mixed $value): array
    {
        $items = [];
        foreach ($this->listValue($value) as $item) {
            if (is_numeric($item)) {
                $items[] = (int) $item;
            }
        }

        return $items;
    }

    /**
     * @return array<int, array{folder_id: int, ai_translation_code: string}>
     */
    private function dimensionFolderListValue(mixed $value): array
    {
        $folders = [];
        foreach ($this->listValue($value) as $folder) {
            if (!is_array($folder)) {
                continue;
            }

            if (!is_numeric($folder['folder_id'] ?? null)) {
                continue;
            }

            $folders[] = [
                'folder_id' => (int) $folder['folder_id'],
                'ai_translation_code' => $this->stringValue($folder['ai_translation_code'] ?? ''),
            ];
        }

        return $folders;
    }

    private function sectionEnabled(mixed $section): bool
    {
        return is_array($section) && $this->boolValue($section['enabled'] ?? true);
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function nullableStringValue(mixed $value): string|null
    {
        $value = $this->stringValue($value);
        return $value === '' ? null : $value;
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return is_scalar($value) && (bool) $value;
    }

    private function nullableIntValue(mixed $value): int|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function intValue(mixed $value): int
    {
        return $this->nullableIntValue($value) ?? 0;
    }
}
