<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

use Blokctl\Action\AppProvision\AppProvisionInstallAction;
use Blokctl\Action\Asset\AssetsConvertToGlobalAction;
use Blokctl\Action\Component\ComponentFieldAddAction;
use Blokctl\Action\Component\ComponentFieldAddResult;
use Blokctl\Action\Folder\FolderCreateAction;
use Blokctl\Action\Space\SpaceDemoRemoveAction;
use Blokctl\Action\Space\SpaceLanguagesEnsureAction;
use Blokctl\Action\Space\SpaceLanguagesRemoveAction;
use Blokctl\Action\SpacePreview\SpacePreviewSetAction;
use Blokctl\Action\Story\StoryCreateAction;
use Blokctl\Action\Story\StoryTranslatedSlugInput;
use Blokctl\Action\Story\StoryTranslatedSlugsEnsureAction;
use Blokctl\Action\Story\StoryWorkflowChangeAction;
use Blokctl\Action\Story\StoriesTagsAssignAction;
use Blokctl\Action\Story\StoriesWorkflowAssignAction;
use Storyblok\ManagementApi\Data\AppProvision;
use Storyblok\ManagementApi\Data\Asset;
use Storyblok\ManagementApi\Data\AssetFolder;
use Storyblok\ManagementApi\Data\Component;
use Storyblok\ManagementApi\Data\Fields\AssetField;
use Storyblok\ManagementApi\Data\Space;
use Storyblok\ManagementApi\Data\SpaceEnvironment;
use Storyblok\ManagementApi\Data\Story;
use Storyblok\ManagementApi\Data\StoryComponent;
use Storyblok\ManagementApi\Data\StoryBaseData;
use Storyblok\ManagementApi\Endpoints\AppProvisionApi;
use Storyblok\ManagementApi\Endpoints\AssetApi;
use Storyblok\ManagementApi\Endpoints\AssetFolderApi;
use Storyblok\ManagementApi\Endpoints\ComponentApi;
use Storyblok\ManagementApi\Endpoints\ManagementApi;
use Storyblok\ManagementApi\Endpoints\SpaceApi;
use Storyblok\ManagementApi\Endpoints\StoryApi;
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\QueryParameters\PaginationParams;
use Storyblok\ManagementApi\QueryParameters\AssetsParams;
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
        string $configDirectory = '.',
    ): SpaceSetupReporter {
        $reporter = new SpaceSetupReporter($dryRun);
        $reporter->start($spaceId, $mode);

        try {
            $this->provisionPreview($reporter, $spaceId, $config, $dryRun, $continueOnError);
            $this->provisionDemoMode($reporter, $spaceId, $config, $dryRun, $continueOnError);
            $this->provisionLanguages($reporter, $spaceId, $config, $dryRun, $continueOnError);
            $this->provisionFolders($reporter, $spaceId, $config, $dryRun, $continueOnError);
            $this->provisionStoryMoves($reporter, $spaceId, $config, $dryRun, $continueOnError);
            $this->provisionAssets($reporter, $spaceId, $config, $configDirectory, $dryRun, $continueOnError);
            $this->provisionStoryUpdates($reporter, $spaceId, $config, $dryRun, $continueOnError);
            $this->provisionStoryCreates($reporter, $spaceId, $config, $configDirectory, $dryRun, $continueOnError);
            $this->provisionWorkflow($reporter, $spaceId, $config, $dryRun, $continueOnError);
            $this->provisionApps($reporter, $spaceId, $config, $dryRun, $continueOnError);
            $this->provisionTranslatedSlugs($reporter, $spaceId, $config, $dryRun, $continueOnError);
            $this->provisionAi($reporter, $spaceId, $config, $dryRun, $continueOnError);
            $this->provisionAiTranslation($reporter, $spaceId, $config, $dryRun, $continueOnError);
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
    private function provisionLanguages(
        SpaceSetupReporter $reporter,
        string $spaceId,
        array $config,
        bool $dryRun,
        bool $continueOnError,
    ): void {
        $space = $this->arrayValue($config['space'] ?? []);
        $languageConfig = $space['languages'] ?? [];
        $addLanguages = $this->languageAddList($languageConfig);
        $removeLanguages = $this->languageRemoveList($languageConfig);
        $conflictingLanguages = array_values(array_intersect($addLanguages, $removeLanguages));
        if ($conflictingLanguages !== []) {
            throw new \RuntimeException('Space languages cannot be both added and removed: ' . implode(', ', $conflictingLanguages));
        }

        if ($addLanguages === [] && $removeLanguages === []) {
            return;
        }

        if ($addLanguages !== []) {
            $this->provisionLanguageAdd($reporter, $spaceId, $addLanguages, $dryRun, $continueOnError);
        }

        if ($removeLanguages !== []) {
            $this->provisionLanguageRemove($reporter, $spaceId, $removeLanguages, $dryRun, $continueOnError);
        }
    }

    /**
     * @param string[] $languages
     */
    private function provisionLanguageAdd(
        SpaceSetupReporter $reporter,
        string $spaceId,
        array $languages,
        bool $dryRun,
        bool $continueOnError,
    ): void {
        $reporter->run('Add space languages', SpaceSetupOperationStatus::Updated, $continueOnError, function () use ($spaceId, $languages, $dryRun): SpaceSetupOperationResult {
            if ($dryRun) {
                return new SpaceSetupOperationResult(
                    SpaceSetupOperationStatus::Updated,
                    'Add space languages',
                    implode(', ', $languages),
                );
            }

            $result = new SpaceLanguagesEnsureAction($this->client)->execute($spaceId, $languages);
            if (!$result->changed) {
                return new SpaceSetupOperationResult(
                    SpaceSetupOperationStatus::Skipped,
                    'Add space languages',
                    'Space languages already match.',
                );
            }

            return new SpaceSetupOperationResult(
                SpaceSetupOperationStatus::Updated,
                'Add space languages',
                'Added language(s): ' . implode(', ', $result->addedLanguages),
            );
        });
    }

    /**
     * @param string[] $languages
     */
    private function provisionLanguageRemove(
        SpaceSetupReporter $reporter,
        string $spaceId,
        array $languages,
        bool $dryRun,
        bool $continueOnError,
    ): void {
        $reporter->run('Remove space languages', SpaceSetupOperationStatus::Updated, $continueOnError, function () use ($spaceId, $languages, $dryRun): SpaceSetupOperationResult {
            if ($dryRun) {
                return new SpaceSetupOperationResult(
                    SpaceSetupOperationStatus::Updated,
                    'Remove space languages',
                    implode(', ', $languages),
                );
            }

            $result = new SpaceLanguagesRemoveAction($this->client)->execute($spaceId, $languages);
            if (!$result->changed) {
                return new SpaceSetupOperationResult(
                    SpaceSetupOperationStatus::Skipped,
                    'Remove space languages',
                    'Space languages already match.',
                );
            }

            return new SpaceSetupOperationResult(
                SpaceSetupOperationStatus::Updated,
                'Remove space languages',
                'Removed language(s): ' . implode(', ', $result->removedLanguages),
            );
        });
    }

    /**
     * @return string[]
     */
    private function languageAddList(mixed $languageConfig): array
    {
        if (!is_array($languageConfig)) {
            return [];
        }

        if (array_is_list($languageConfig)) {
            return $this->stringListValue($languageConfig);
        }

        return $this->stringListValue($languageConfig['add'] ?? $languageConfig['ensure'] ?? []);
    }

    /**
     * @return string[]
     */
    private function languageRemoveList(mixed $languageConfig): array
    {
        if (!is_array($languageConfig) || array_is_list($languageConfig)) {
            return [];
        }

        return $this->stringListValue($languageConfig['remove'] ?? []);
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
    private function provisionStoryUpdates(
        SpaceSetupReporter $reporter,
        string $spaceId,
        array $config,
        bool $dryRun,
        bool $continueOnError,
    ): void {
        $stories = $this->arrayValue($config['stories'] ?? []);
        foreach ($this->listValue($stories['update'] ?? []) as $update) {
            if (!is_array($update)) {
                continue;
            }

            $storySlug = $this->nullableStringValue($update['slug'] ?? null);
            $storyId = $this->nullableStringValue($update['id'] ?? null);
            $components = $this->listValue($update['components'] ?? []);
            $label = 'Update story components: ' . ($storySlug ?? $storyId ?? '');
            $reporter->run($label, SpaceSetupOperationStatus::Updated, $continueOnError, function () use ($spaceId, $storySlug, $storyId, $components, $dryRun, $label): SpaceSetupOperationResult {
                if (($storySlug === null && $storyId === null) || ($storySlug !== null && $storyId !== null)) {
                    throw new \RuntimeException('Story update entries require exactly one of slug or id.');
                }

                if ($components === []) {
                    throw new \RuntimeException('Story update entries require components.');
                }

                if ($dryRun) {
                    return new SpaceSetupOperationResult(
                        SpaceSetupOperationStatus::Updated,
                        $label,
                        count($components) . ' component update(s) planned.',
                    );
                }

                $storyApi = new StoryApi($this->client, $spaceId);
                $resolvedStoryId = $storyId ?? $this->requireStoryIdBySlug($storyApi, (string) $storySlug);
                $story = Story::make($storyApi->get($resolvedStoryId)->data()->toArray());
                $content = $story->content()->toArray();
                $changes = 0;

                foreach ($components as $componentUpdate) {
                    if (!is_array($componentUpdate)) {
                        continue;
                    }

                    $path = $this->stringValue($componentUpdate['path'] ?? '');
                    $expectedComponent = $this->stringValue($componentUpdate['component'] ?? '');
                    $fields = $this->arrayValue($componentUpdate['fields'] ?? []);
                    if ($path === '' || $expectedComponent === '' || $fields === []) {
                        throw new \RuntimeException('Story component updates require path, component, and fields.');
                    }

                    $fields = $this->resolveStorySetupDirectives($spaceId, $fields);
                    $component = &$this->componentAtPath($content, $path);
                    $actualComponent = $this->stringValue($component['component'] ?? '');
                    if ($actualComponent !== $expectedComponent) {
                        throw new \RuntimeException(sprintf(
                            'Expected component "%s" at "%s", found "%s".',
                            $expectedComponent,
                            $path,
                            $actualComponent === '' ? 'none' : $actualComponent,
                        ));
                    }

                    if ($this->mergeDeclaredFields($component, $fields)) {
                        ++$changes;
                    }

                    unset($component);
                }

                if ($changes === 0) {
                    return new SpaceSetupOperationResult(
                        SpaceSetupOperationStatus::Skipped,
                        $label,
                        'Story component fields already match.',
                    );
                }

                $story->setContent(StoryComponent::make($content));
                $response = $storyApi->update($resolvedStoryId, $story);
                if (!$response->isOk()) {
                    throw new \RuntimeException('Failed to update story components: ' . $response->getErrorMessage());
                }

                return new SpaceSetupOperationResult(
                    SpaceSetupOperationStatus::Updated,
                    $label,
                    $changes . ' component(s) updated.',
                );
            });
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function provisionStoryCreates(
        SpaceSetupReporter $reporter,
        string $spaceId,
        array $config,
        string $configDirectory,
        bool $dryRun,
        bool $continueOnError,
    ): void {
        $stories = $this->arrayValue($config['stories'] ?? []);
        foreach ($this->listValue($stories['create'] ?? []) as $create) {
            if (!is_array($create)) {
                continue;
            }

            $name = $this->stringValue($create['name'] ?? '');
            $slug = $this->nullableStringValue($create['slug'] ?? null);
            $parentId = $this->nullableIntValue($create['parent_id'] ?? null);
            $parentSlug = $this->nullableStringValue($create['parent_slug'] ?? null);
            $publish = $this->boolValue($create['publish'] ?? false);
            $label = 'Create story: ' . ($slug ?? $name);
            $reporter->run($label, SpaceSetupOperationStatus::Created, $continueOnError, function () use ($spaceId, $create, $name, $slug, $parentId, $parentSlug, $publish, $configDirectory, $dryRun, $label): SpaceSetupOperationResult {
                if ($name === '') {
                    throw new \RuntimeException('Story create entries require name.');
                }

                if ($parentId !== null && $parentSlug !== null) {
                    throw new \RuntimeException('Story create entries must not combine parent_id and parent_slug.');
                }

                if ($dryRun) {
                    return new SpaceSetupOperationResult(
                        SpaceSetupOperationStatus::Created,
                        $label,
                        $parentSlug !== null ? 'Parent: ' . $parentSlug : 'Parent ID: ' . ($parentId ?? 0),
                    );
                }

                $content = $this->resolveStorySetupDirectives($spaceId, $this->storyCreateContent($create, $configDirectory));
                $storySlug = $slug ?? $this->slugify($name);
                if ($this->storyExistsBySlug($spaceId, $storySlug)) {
                    return new SpaceSetupOperationResult(
                        SpaceSetupOperationStatus::Skipped,
                        $label,
                        'Story already exists.',
                    );
                }

                $resolvedParentId = $parentId ?? ($parentSlug === null ? 0 : $this->requireFolderId($spaceId, $parentSlug));
                $result = new StoryCreateAction($this->client)->execute(
                    spaceId: $spaceId,
                    name: $name,
                    content: $content,
                    slug: $storySlug,
                    parentId: $resolvedParentId,
                    publish: $publish,
                );

                return new SpaceSetupOperationResult(
                    SpaceSetupOperationStatus::Created,
                    $label,
                    'Story ID: ' . $result->story->id(),
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
        if ($this->boolValue($workflow['assign_unstaged'] ?? false)) {
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

        foreach ($this->listValue($workflow['assign'] ?? []) as $assignment) {
            if (!is_array($assignment)) {
                continue;
            }

            $stories = $this->arrayValue($assignment['stories'] ?? []);
            $storySlugs = $this->stringListValue($stories['slugs'] ?? []);
            $storyIds = $this->stringListValue($stories['ids'] ?? []);
            $workflowName = $this->nullableStringValue($assignment['workflow'] ?? null);
            $workflowId = $this->nullableStringValue($assignment['workflow_id'] ?? null);
            $stageName = $this->nullableStringValue($assignment['stage'] ?? null);
            $stageId = $this->nullableIntValue($assignment['stage_id'] ?? null);
            $label = 'Assign workflow stage: ' . ($workflowName ?? $workflowId ?? 'default') . '/' . ($stageName ?? $stageId ?? '');

            $reporter->run($label, SpaceSetupOperationStatus::Updated, $continueOnError, function () use ($spaceId, $storySlugs, $storyIds, $workflowName, $workflowId, $stageName, $stageId, $dryRun, $label): SpaceSetupOperationResult {
                if ($storySlugs === [] && $storyIds === []) {
                    throw new \RuntimeException('workflow.assign entries require stories.slugs or stories.ids.');
                }

                if ($workflowName !== null && $workflowId !== null) {
                    throw new \RuntimeException('workflow.assign entries require only one of workflow or workflow_id.');
                }

                if (($stageName === null && $stageId === null) || ($stageName !== null && $stageId !== null)) {
                    throw new \RuntimeException('workflow.assign entries require exactly one of stage or stage_id.');
                }

                $storyCount = count(array_unique($storySlugs)) + count(array_unique($storyIds));
                if ($dryRun) {
                    return new SpaceSetupOperationResult(
                        SpaceSetupOperationStatus::Updated,
                        $label,
                        $storyCount . ' story assignment(s) planned.',
                    );
                }

                $action = new StoryWorkflowChangeAction($this->client);
                $assigned = 0;
                foreach (array_unique($storySlugs) as $storySlug) {
                    $action->execute(
                        spaceId: $spaceId,
                        storySlug: $storySlug,
                        stageName: $stageName,
                        stageId: $stageId,
                        workflowName: $workflowName,
                        workflowId: $workflowId,
                    );
                    ++$assigned;
                }

                foreach (array_unique($storyIds) as $storyId) {
                    $action->execute(
                        spaceId: $spaceId,
                        storyId: $storyId,
                        stageName: $stageName,
                        stageId: $stageId,
                        workflowName: $workflowName,
                        workflowId: $workflowId,
                    );
                    ++$assigned;
                }

                return new SpaceSetupOperationResult(
                    SpaceSetupOperationStatus::Updated,
                    $label,
                    $assigned . ' story assignment(s) applied.',
                );
            });
        }
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
    private function provisionTranslatedSlugs(
        SpaceSetupReporter $reporter,
        string $spaceId,
        array $config,
        bool $dryRun,
        bool $continueOnError,
    ): void {
        $space = $this->arrayValue($config['space'] ?? []);
        $knownEnabledLanguages = $this->languageAddList($space['languages'] ?? []);
        $stories = $this->arrayValue($config['stories'] ?? []);
        $translatedSlugs = $this->listValue($stories['translated_slugs'] ?? []);
        foreach ($translatedSlugs as $translatedSlug) {
            if (!is_array($translatedSlug)) {
                continue;
            }

            $storySlug = $this->nullableStringValue($translatedSlug['story_slug'] ?? $translatedSlug['slug'] ?? null);
            $storyId = $this->nullableStringValue($translatedSlug['story_id'] ?? $translatedSlug['id'] ?? null);
            $translations = $this->translatedSlugInputs($translatedSlug);
            $label = 'Ensure translated slugs: ' . ($storySlug ?? $storyId ?? '');
            if (count($translations) === 1) {
                $label = 'Ensure translated slug: ' . ($storySlug ?? $storyId ?? '') . '.' . $translations[0]->lang;
            }

            $reporter->run($label, SpaceSetupOperationStatus::Updated, $continueOnError, function () use ($spaceId, $storySlug, $storyId, $translations, $knownEnabledLanguages, $dryRun, $label): SpaceSetupOperationResult {
                if (($storySlug === null && $storyId === null) || ($storySlug !== null && $storyId !== null)) {
                    throw new \RuntimeException('Translated slug entries require exactly one of story_slug or story_id.');
                }

                if ($translations === []) {
                    throw new \RuntimeException('Translated slug entries require lang and translated_slug.');
                }

                if ($dryRun) {
                    return new SpaceSetupOperationResult(
                        SpaceSetupOperationStatus::Updated,
                        $label,
                        $this->translatedSlugDetail($translations),
                    );
                }

                $result = new StoryTranslatedSlugsEnsureAction($this->client)->execute(
                    spaceId: $spaceId,
                    storySlug: $storySlug,
                    storyId: $storyId,
                    translations: $translations,
                    knownEnabledLanguages: $knownEnabledLanguages,
                );
                if (!$result->changed) {
                    return new SpaceSetupOperationResult(
                        SpaceSetupOperationStatus::Skipped,
                        $label,
                        'Translated slug already matches.',
                    );
                }

                return new SpaceSetupOperationResult(
                    SpaceSetupOperationStatus::Updated,
                    $label,
                    $result->changedCount . ' translated slug(s) updated.',
                );
            });
        }
    }

    /**
     * @param array<string, mixed> $translatedSlug
     * @return list<StoryTranslatedSlugInput>
     */
    private function translatedSlugInputs(array $translatedSlug): array
    {
        if (array_key_exists('translations', $translatedSlug)) {
            $translations = $translatedSlug['translations'];
            if (!is_array($translations)) {
                return [];
            }

            $inputs = [];
            foreach ($translations as $lang => $translation) {
                if (is_array($translation)) {
                    $inputLang = is_string($lang) ? $lang : $this->stringValue($translation['lang'] ?? '');
                    $slug = $this->stringValue($translation['slug'] ?? $translation['translated_slug'] ?? '');
                    $name = $this->nullableStringValue($translation['name'] ?? null);
                    $published = array_key_exists('published', $translation)
                        ? $this->boolValue($translation['published'])
                        : null;
                } else {
                    $inputLang = is_string($lang) ? $lang : '';
                    $slug = $this->stringValue($translation);
                    $name = null;
                    $published = null;
                }

                $inputs[] = new StoryTranslatedSlugInput(
                    lang: $inputLang,
                    translatedSlug: $this->normalizedSlug($slug),
                    name: $name,
                    published: $published,
                );
            }

            return $inputs;
        }

        return [
            new StoryTranslatedSlugInput(
                lang: $this->stringValue($translatedSlug['lang'] ?? ''),
                translatedSlug: $this->normalizedSlug($this->stringValue($translatedSlug['translated_slug'] ?? '')),
                name: $this->nullableStringValue($translatedSlug['name'] ?? null),
                published: array_key_exists('published', $translatedSlug)
                    ? $this->boolValue($translatedSlug['published'])
                    : null,
            ),
        ];
    }

    /**
     * @param list<StoryTranslatedSlugInput> $translations
     */
    private function translatedSlugDetail(array $translations): string
    {
        if (count($translations) === 1) {
            return 'Language: ' . $translations[0]->lang . '; translated slug: ' . $translations[0]->translatedSlug;
        }

        $details = array_map(
            static fn(StoryTranslatedSlugInput $translation): string => $translation->lang . '=' . $translation->translatedSlug,
            $translations,
        );

        return count($translations) . ' translation(s) planned: ' . implode(', ', $details);
    }

    private function normalizedSlug(string $slug): string
    {
        return trim($slug, '/');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function provisionAi(
        SpaceSetupReporter $reporter,
        string $spaceId,
        array $config,
        bool $dryRun,
        bool $continueOnError,
    ): void {
        $ai = $this->arrayValue($config['ai'] ?? []);
        if ($ai === []) {
            return;
        }

        $reporter->run('Configure Storyblok AI', SpaceSetupOperationStatus::Updated, $continueOnError, function () use ($spaceId, $ai, $dryRun): SpaceSetupOperationResult|null {
            $desired = [];
            if (array_key_exists('enabled', $ai)) {
                $desired['ai_text_generator_disabled'] = !$this->boolValue($ai['enabled']);
            }

            if (array_key_exists('inherit_org_configuration', $ai)) {
                $desired['inherit_org_ai_configuration'] = $this->boolValue($ai['inherit_org_configuration']);
            }

            if ($dryRun) {
                return null;
            }

            $spaceApi = new SpaceApi($this->client);
            $space = $spaceApi->get($spaceId)->data()->toArray();
            $changes = [];
            foreach ($desired as $key => $value) {
                if (!array_key_exists($key, $space) || $this->boolValue($space[$key]) !== $value) {
                    $changes[$key] = $value;
                }
            }

            if ($changes === []) {
                return new SpaceSetupOperationResult(
                    SpaceSetupOperationStatus::Skipped,
                    'Configure Storyblok AI',
                    'Storyblok AI configuration already matches.',
                );
            }

            $activation = [
                'ai_text_generator_disabled' => false,
                'inherit_org_ai_configuration' => false,
            ];
            $response = $desired === $activation
                ? $spaceApi->activateAi($spaceId)
                : $spaceApi->update($spaceId, Space::forUpdate($changes));
            if (!$response->isOk()) {
                throw new \RuntimeException('Failed to configure Storyblok AI: ' . $response->getErrorMessage());
            }

            return null;
        });
    }

    /**
     * @param array<string, mixed> $config
     */
    private function provisionAiTranslation(
        SpaceSetupReporter $reporter,
        string $spaceId,
        array $config,
        bool $dryRun,
        bool $continueOnError,
    ): void {
        $aiTranslation = $this->arrayValue($config['ai_translation'] ?? []);
        if ($aiTranslation === []) {
            return;
        }

        $disclaimerId = $this->intValue($aiTranslation['disclaimer_id'] ?? 0);
        if ($disclaimerId < 1) {
            throw new \RuntimeException('AI Translation disclaimer_id must be a positive integer.');
        }

        $reporter->run('Configure AI Translation disclaimer', SpaceSetupOperationStatus::Updated, $continueOnError, function () use ($spaceId, $disclaimerId, $dryRun): SpaceSetupOperationResult|null {
            if ($dryRun) {
                return null;
            }

            $spaceApi = new SpaceApi($this->client);
            $space = $spaceApi->get($spaceId)->data()->toArray();
            if ($this->nullableIntValue($space['disclaimer_id'] ?? null) === $disclaimerId) {
                return new SpaceSetupOperationResult(
                    SpaceSetupOperationStatus::Skipped,
                    'Configure AI Translation disclaimer',
                    'AI Translation disclaimer already matches.',
                );
            }

            $response = $spaceApi->update($spaceId, Space::forUpdate([
                'disclaimer_id' => $disclaimerId,
            ]));
            if (!$response->isOk()) {
                throw new \RuntimeException('Failed to configure AI Translation disclaimer: ' . $response->getErrorMessage());
            }

            return null;
        });
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
    private function provisionAssets(
        SpaceSetupReporter $reporter,
        string $spaceId,
        array $config,
        string $configDirectory,
        bool $dryRun,
        bool $continueOnError,
    ): void {
        $assets = $this->arrayValue($config['assets'] ?? []);
        $uploadDirectories = $this->listValue($assets['upload_directory'] ?? []);
        $convertToGlobal = $this->listValue($assets['convert_to_global'] ?? []);
        if ($uploadDirectories === [] && $convertToGlobal === []) {
            return;
        }

        $folderState = [
            'folders' => [],
            'paths' => [],
        ];
        if (!$dryRun && $uploadDirectories !== []) {
            $folderState = $this->assetFolderState($spaceId);
        }

        $assetFilenameCache = [];
        foreach ($uploadDirectories as $uploadDirectory) {
            if (!is_array($uploadDirectory)) {
                continue;
            }

            $source = $this->stringValue($uploadDirectory['source'] ?? '');
            $targetFolder = trim($this->stringValue($uploadDirectory['target_folder'] ?? ''), '/');
            $recursive = $this->boolValue($uploadDirectory['recursive'] ?? false);
            $include = $this->stringListValue($uploadDirectory['include'] ?? []);
            if ($targetFolder === '') {
                throw new \RuntimeException('Asset upload directories require target_folder.');
            }

            if (str_contains($targetFolder, '//')) {
                throw new \RuntimeException('Asset target_folder must not contain empty path segments.');
            }

            $files = new SpaceSetupAssetDirectoryScanner()->scan(
                $configDirectory,
                $source,
                $recursive,
                $include,
            );
            $folderPaths = [$targetFolder];
            foreach ($files as $file) {
                if ($recursive && $file['relative_directory'] !== '') {
                    $folderPaths[] = $targetFolder . '/' . $file['relative_directory'];
                }
            }

            $folderPaths = array_values(array_unique($folderPaths));
            usort($folderPaths, static fn(string $left, string $right): int => substr_count($left, '/') <=> substr_count($right, '/'));

            foreach ($folderPaths as $folderPath) {
                $label = 'Ensure asset folder: ' . $folderPath;
                $reporter->run($label, SpaceSetupOperationStatus::Created, $continueOnError, function () use ($spaceId, $folderPath, $dryRun, &$folderState, $label): SpaceSetupOperationResult|null {
                    if ($dryRun) {
                        return null;
                    }

                    $created = $this->ensureAssetFolderPath($spaceId, $folderPath, $folderState);
                    if (!$created) {
                        return new SpaceSetupOperationResult(
                            SpaceSetupOperationStatus::Skipped,
                            $label,
                            'Asset folder already exists.',
                        );
                    }

                    return null;
                });
            }

            foreach ($files as $file) {
                $folderPath = $targetFolder;
                if ($recursive && $file['relative_directory'] !== '') {
                    $folderPath .= '/' . $file['relative_directory'];
                }

                $label = 'Upload asset: ' . $folderPath . '/' . $file['filename'];
                $reporter->run($label, SpaceSetupOperationStatus::Created, $continueOnError, function () use ($spaceId, $folderPath, $file, $dryRun, &$folderState, &$assetFilenameCache, $label): SpaceSetupOperationResult|null {
                    if ($dryRun) {
                        return new SpaceSetupOperationResult(
                            SpaceSetupOperationStatus::Created,
                            $label,
                            $file['relative_path'],
                        );
                    }

                    $folderId = $folderState['paths'][$folderPath] ?? null;
                    if (!is_int($folderId)) {
                        throw new \RuntimeException('Asset folder was not resolved: ' . $folderPath);
                    }

                    $assetFilenameCache[$folderId] ??= $this->assetFilenamesInFolder($spaceId, $folderId);
                    if (in_array($file['filename'], $assetFilenameCache[$folderId], true)) {
                        return new SpaceSetupOperationResult(
                            SpaceSetupOperationStatus::Skipped,
                            $label,
                            'Asset with the same filename already exists in the target folder.',
                        );
                    }

                    new AssetApi($this->client, $spaceId)->upload($file['path'], $folderId)->data();
                    $assetFilenameCache[$folderId][] = $file['filename'];
                    return null;
                });
            }
        }

        foreach ($convertToGlobal as $conversion) {
            if (!is_array($conversion)) {
                continue;
            }

            $targetSharedFolderId = $this->intValue($conversion['target_shared_folder_id'] ?? 0);
            $assetIds = $this->intListValue($conversion['asset_ids'] ?? []);
            $assetId = $this->nullableIntValue($conversion['asset_id'] ?? null);
            if ($assetId !== null) {
                $assetIds[] = $assetId;
            }

            $assetIds = array_values($assetIds);

            $sourceFolderId = $this->nullableIntValue($conversion['source_folder_id'] ?? null);
            $sourceFolderName = $this->nullableStringValue($conversion['source_folder_name'] ?? null);
            $filters = $this->arrayValue($conversion['filters'] ?? []);
            $filetype = $this->nullableStringValue($filters['filetype'] ?? null);
            $extensions = array_values($this->stringListValue($filters['extensions'] ?? []));
            $tags = array_values($this->stringListValue($filters['tags'] ?? []));
            $label = 'Convert assets to global folder: ' . $targetSharedFolderId;

            $reporter->run($label, SpaceSetupOperationStatus::Updated, $continueOnError, function () use ($spaceId, $targetSharedFolderId, $assetIds, $sourceFolderId, $sourceFolderName, $filetype, $extensions, $tags, $dryRun, $continueOnError, $label): SpaceSetupOperationResult {
                if ($targetSharedFolderId < 1) {
                    throw new \RuntimeException('Asset conversion entries require target_shared_folder_id.');
                }

                if ($dryRun) {
                    $detail = $assetIds !== []
                        ? 'Assets: ' . implode(', ', array_values(array_unique($assetIds)))
                        : ($sourceFolderId !== null
                            ? 'Source folder ID: ' . $sourceFolderId
                            : 'Source folder name: ' . ($sourceFolderName ?? ''));

                    return new SpaceSetupOperationResult(
                        SpaceSetupOperationStatus::Updated,
                        $label,
                        $detail,
                    );
                }

                $result = new AssetsConvertToGlobalAction($this->client)->execute(
                    spaceId: $spaceId,
                    targetSharedFolderId: $targetSharedFolderId,
                    assetIds: $assetIds,
                    sourceFolderId: $sourceFolderId,
                    sourceFolderName: $sourceFolderName,
                    filetype: $filetype,
                    extensions: $extensions,
                    tags: $tags,
                    continueOnError: $continueOnError,
                );

                if ($result->total() === 0) {
                    return new SpaceSetupOperationResult(
                        SpaceSetupOperationStatus::Skipped,
                        $label,
                        'No matching assets found.',
                    );
                }

                if ($result->failed() > 0) {
                    throw new \RuntimeException(implode(' | ', $result->errors));
                }

                return new SpaceSetupOperationResult(
                    SpaceSetupOperationStatus::Updated,
                    $label,
                    $result->converted() . ' asset(s) converted.',
                );
            });
        }
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
            $reporter->run('Ensure component field: ' . $componentName . '.' . $fieldName, SpaceSetupOperationStatus::Created, $continueOnError, function () use ($spaceId, $field, $componentName, $fieldName, $dryRun): ?SpaceSetupOperationResult {
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

    private function requireStoryIdBySlug(StoryApi $storyApi, string $slug): string
    {
        $stories = $storyApi->page(new StoriesParams(withSlug: $slug))->data();
        if (count($stories) !== 1) {
            throw new \RuntimeException('Story not found with slug: ' . $slug);
        }

        /** @var array{id: int|string} $story */
        $story = $stories[0];

        return (string) $story['id'];
    }

    private function storyExistsBySlug(string $spaceId, string $slug): bool
    {
        return count(new StoryApi($this->client, $spaceId)->page(new StoriesParams(withSlug: $slug))->data()) > 0;
    }

    private function slugify(string $name): string
    {
        $slug = mb_strtolower($name);
        $slug = (string) preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = (string) preg_replace('/[\s-]+/', '-', $slug);

        return trim($slug, '-');
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    private function &componentAtPath(array &$content, string $path): array
    {
        $segments = $this->pathSegments($path);
        if ($segments === [] || $segments[0] !== 'content') {
            throw new \RuntimeException('Story component update paths must start with content.');
        }

        $current = &$content;
        foreach (array_slice($segments, 1) as $segment) {
            if (is_string($segment)) {
                if (!is_array($current) || !array_key_exists($segment, $current)) {
                    throw new \RuntimeException('Path not found: ' . $path);
                }

                $current = &$current[$segment];
                continue;
            }

            if (!is_array($current) || !array_key_exists($segment, $current)) {
                throw new \RuntimeException('Path not found: ' . $path);
            }

            $current = &$current[$segment];
        }

        if (!is_array($current)) {
            throw new \RuntimeException('Path does not resolve to a component object: ' . $path);
        }

        /** @var array<string, mixed> $current */
        return $current;
    }

    /**
     * @return list<int|string>
     */
    private function pathSegments(string $path): array
    {
        $segments = [];
        $length = strlen($path);
        $offset = 0;
        $expectSegment = true;
        while ($offset < $length) {
            if ($expectSegment) {
                if (!preg_match('/\\G[A-Za-z_][A-Za-z0-9_-]*/', $path, $match, 0, $offset)) {
                    throw new \RuntimeException('Invalid path: ' . $path);
                }

                $segments[] = $match[0];
                $offset += strlen($match[0]);
                $expectSegment = false;
                continue;
            }

            if ($path[$offset] === '.') {
                ++$offset;
                $expectSegment = true;
                continue;
            }

            if ($path[$offset] === '[' && preg_match('/\\G\\[(\\d+)]/', $path, $match, 0, $offset)) {
                $segments[] = (int) $match[1];
                $offset += strlen($match[0]);
                continue;
            }

            throw new \RuntimeException('Invalid path: ' . $path);
        }

        if ($segments === [] || $expectSegment) {
            throw new \RuntimeException('Invalid path: ' . $path);
        }

        return $segments;
    }

    /**
     * @param array<mixed> $target
     * @param array<mixed> $fields
     */
    private function mergeDeclaredFields(array &$target, array $fields): bool
    {
        $changed = false;
        foreach ($fields as $field => $value) {
            if (!is_string($field) || $field === '') {
                throw new \RuntimeException('Story component field names must be non-empty strings.');
            }

            $existing = $target[$field] ?? null;
            if (is_array($existing) && is_array($value) && $this->isAssociativeArray($existing) && $this->isAssociativeArray($value)) {
                if ($this->mergeDeclaredFields($existing, $value)) {
                    $target[$field] = $existing;
                    $changed = true;
                }

                continue;
            }

            if ($existing !== $value) {
                $target[$field] = $value;
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * @param array<mixed> $value
     */
    private function isAssociativeArray(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    /**
     * @param array<string, mixed> $create
     * @return array<string, mixed>
     */
    private function storyCreateContent(array $create, string $configDirectory): array
    {
        if (array_key_exists('content', $create)) {
            return $this->arrayValue($create['content']);
        }

        $contentFile = $this->stringValue($create['content_file'] ?? '');
        if ($contentFile === '') {
            throw new \RuntimeException('Story create entries require content or content_file.');
        }

        $path = $this->resolveConfigRelativePath($configDirectory, $contentFile);
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException('Failed to read story content file: ' . $path);
        }

        try {
            $content = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $jsonException) {
            throw new \RuntimeException('Invalid story content JSON: ' . $jsonException->getMessage(), $jsonException->getCode(), previous: $jsonException);
        }

        return $this->arrayValue($content);
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    private function resolveStorySetupDirectives(string $spaceId, array $content): array
    {
        /** @var array<string, mixed> $resolved */
        $resolved = [];
        foreach ($content as $key => $value) {
            $resolved[$key] = $this->resolveStorySetupValue($spaceId, $value);
        }

        return $resolved;
    }

    private function resolveStorySetupValue(string $spaceId, mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($this->isAssetDirective($value)) {
            /** @var array<string, mixed> $assetConfig */
            $assetConfig = $value['asset'];

            return $this->resolveAssetDirective($spaceId, $assetConfig);
        }

        $resolved = [];
        foreach ($value as $key => $item) {
            $resolved[$key] = $this->resolveStorySetupValue($spaceId, $item);
        }

        return $resolved;
    }

    /**
     * @param array<mixed> $value
     */
    private function isAssetDirective(array $value): bool
    {
        return count($value) === 1
            && array_key_exists('asset', $value)
            && is_array($value['asset'])
            && array_key_exists('_find', $value['asset']);
    }

    /**
     * @param array<string, mixed> $assetConfig
     * @return array<string, mixed>
     */
    private function resolveAssetDirective(string $spaceId, array $assetConfig): array
    {
        $find = $this->arrayValue($assetConfig['_find'] ?? []);
        if ($find === []) {
            throw new \RuntimeException('Asset directives require _find criteria.');
        }

        foreach (array_keys($assetConfig) as $key) {
            if (is_string($key) && str_starts_with($key, '_') && $key !== '_find') {
                throw new \RuntimeException('Unsupported asset directive key: ' . $key);
            }
        }

        $asset = $this->findAsset($spaceId, $find);
        $field = AssetField::makeFromAsset($asset)->toArray();
        foreach ($assetConfig as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (str_starts_with($key, '_')) {
                continue;
            }

            $field[$key] = $this->resolveStorySetupValue($spaceId, $value);
        }

        return $field;
    }

    /**
     * @param array<string, mixed> $find
     */
    private function findAsset(string $spaceId, array $find): Asset
    {
        $requireUnique = $this->boolValue($find['require_unique'] ?? true);
        $assets = $this->findAssets($spaceId, $find);
        if ($assets === []) {
            throw new \RuntimeException('No asset found for _find criteria.');
        }

        if ($requireUnique && count($assets) > 1) {
            throw new \RuntimeException('Multiple assets found for _find criteria.');
        }

        return $assets[0];
    }

    /**
     * @param array<string, mixed> $find
     * @return list<Asset>
     */
    private function findAssets(string $spaceId, array $find): array
    {
        $folder = $find['in_folder'] ?? null;
        $folderId = null;
        if ($folder !== null) {
            $folderId = is_int($folder) ? $folder : $this->resolveAssetFolderIdForFind($spaceId, $this->stringValue($folder));
        }

        $tags = $this->stringListValue($find['tags'] ?? $find['with_tags'] ?? []);
        $assetApi = new AssetApi($this->client, $spaceId);
        $assets = [];
        $page = 1;
        do {
            $pageAssets = $assetApi->page(
                new AssetsParams(
                    inFolder: $folderId,
                    search: $this->nullableStringValue($find['search'] ?? null),
                    byAlt: $this->nullableStringValue($find['by_alt'] ?? null),
                    byCopyright: $this->nullableStringValue($find['by_copyright'] ?? null),
                    byTitle: $this->nullableStringValue($find['by_title'] ?? null),
                    withTags: $tags === [] ? null : $tags,
                ),
                new PaginationParams($page, 1000),
            )->data();

            foreach ($pageAssets as $asset) {
                if ($asset instanceof Asset) {
                    $assets[] = $asset;
                }
            }

            ++$page;
        } while (count($pageAssets) === 1000);

        return $assets;
    }

    private function resolveAssetFolderIdForFind(string $spaceId, string $folder): int
    {
        if ($folder === '') {
            throw new \RuntimeException('Asset _find in_folder must not be empty.');
        }

        if (ctype_digit($folder)) {
            return (int) $folder;
        }

        $state = $this->assetFolderState($spaceId);
        $folderId = $state['paths'][$folder] ?? null;
        if (!is_int($folderId)) {
            throw new \RuntimeException('Asset folder not found for _find: ' . $folder);
        }

        return $folderId;
    }

    private function resolveConfigRelativePath(string $configDirectory, string $path): string
    {
        if ($path === '') {
            throw new \RuntimeException('Path must not be empty.');
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return $path;
        }

        return rtrim($configDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
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
        $label = 'Ensure component field: ' . $componentName . '.' . $fieldName;

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
                customizeToolbar: array_key_exists('customize_toolbar', $field)
                    ? $this->boolValue($field['customize_toolbar'])
                    : null,
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
            'customize_toolbar' => ['customize_toolbar'],
        ];

        foreach ($aliases as $target => $sourceKeys) {
            foreach ($sourceKeys as $sourceKey) {
                if (!array_key_exists($sourceKey, $declared)) {
                    continue;
                }

                $value = $declared[$sourceKey];
                if ($target === 'pos') {
                    $value = $this->nullableIntValue($value);
                } elseif (in_array($target, ['required', 'translatable', 'customize_toolbar'], true)) {
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
     * @return array{folders: array<int, array{id: int, name: string, parent_id: int|null}>, paths: array<string, int>}
     */
    private function assetFolderState(string $spaceId): array
    {
        $folders = [];
        foreach (new AssetFolderApi($this->client, $spaceId)->page()->data() as $folder) {
            if (!$folder instanceof AssetFolder) {
                continue;
            }

            $folders[] = [
                'id' => (int) $folder->id(),
                'name' => $folder->name(),
                'parent_id' => $folder->parentId(),
            ];
        }

        return [
            'folders' => $folders,
            'paths' => $this->assetFolderPaths($folders),
        ];
    }

    /**
     * @param array{folders: array<int, array{id: int, name: string, parent_id: int|null}>, paths: array<string, int>} $state
     */
    private function ensureAssetFolderPath(string $spaceId, string $path, array &$state): bool
    {
        if (array_key_exists($path, $state['paths'])) {
            return false;
        }

        $segments = explode('/', $path);
        $currentPath = '';
        $parentId = null;
        $created = false;
        foreach ($segments as $segment) {
            $currentPath = $currentPath === '' ? $segment : $currentPath . '/' . $segment;
            if (array_key_exists($currentPath, $state['paths'])) {
                $parentId = $state['paths'][$currentPath];
                continue;
            }

            $folder = new AssetFolder($segment);
            if ($parentId !== null) {
                $folder->set('parent_id', $parentId);
            }

            $response = new AssetFolderApi($this->client, $spaceId)->create($folder);
            if (!$response->isOk()) {
                throw new \RuntimeException('Failed to create asset folder "' . $currentPath . '": ' . $response->getErrorMessage());
            }

            $createdFolder = $response->data();
            $parentId = (int) $createdFolder->id();
            $state['folders'][] = [
                'id' => $parentId,
                'name' => $createdFolder->name(),
                'parent_id' => $createdFolder->parentId(),
            ];
            $state['paths'][$currentPath] = $parentId;
            $created = true;
        }

        return $created;
    }

    /**
     * @param array<int, array{id: int, name: string, parent_id: int|null}> $folders
     *
     * @return array<string, int>
     */
    private function assetFolderPaths(array $folders): array
    {
        $byId = [];
        foreach ($folders as $folder) {
            $byId[$folder['id']] = $folder;
        }

        $paths = [];
        foreach ($folders as $folder) {
            $segments = [$folder['name']];
            $parentId = $folder['parent_id'];
            $visited = [];
            while ($parentId !== null && array_key_exists($parentId, $byId) && !in_array($parentId, $visited, true)) {
                $visited[] = $parentId;
                array_unshift($segments, $byId[$parentId]['name']);
                $parentId = $byId[$parentId]['parent_id'];
            }

            $path = implode('/', $segments);
            if (array_key_exists($path, $paths)) {
                throw new \RuntimeException('Multiple asset folders found with path: ' . $path);
            }

            $paths[$path] = $folder['id'];
        }

        return $paths;
    }

    /**
     * @return string[]
     */
    private function assetFilenamesInFolder(string $spaceId, int $folderId): array
    {
        $filenames = [];
        $page = 1;
        do {
            $assets = new AssetApi($this->client, $spaceId)->page(
                new AssetsParams(inFolder: $folderId),
                new PaginationParams($page, 1000),
            )->data();
            foreach ($assets as $asset) {
                if (!$asset instanceof Asset) {
                    continue;
                }

                $path = parse_url($asset->filename(), PHP_URL_PATH);
                $filenames[] = rawurldecode(basename(is_string($path) ? $path : $asset->filename()));
            }

            ++$page;
        } while (count($assets) === 1000);

        return array_values(array_unique($filenames));
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
