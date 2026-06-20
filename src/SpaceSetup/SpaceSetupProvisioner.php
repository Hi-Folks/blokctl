<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

use Blokctl\Action\AppProvision\AppProvisionInstallAction;
use Blokctl\Action\Asset\AssetsConvertToGlobalAction;
use Blokctl\Action\Component\ComponentFieldAddAction;
use Blokctl\Action\Component\ComponentFieldAddResult;
use Blokctl\Action\Folder\FolderCreateAction;
use Blokctl\Action\Space\SpaceDemoRemoveAction;
use Blokctl\Action\SpacePreview\SpacePreviewSetAction;
use Blokctl\Action\Story\StoriesTagsAssignAction;
use Blokctl\Action\Story\StoriesWorkflowAssignAction;
use Storyblok\ManagementApi\Data\AppProvision;
use Storyblok\ManagementApi\Data\Asset;
use Storyblok\ManagementApi\Data\AssetFolder;
use Storyblok\ManagementApi\Data\Component;
use Storyblok\ManagementApi\Data\Space;
use Storyblok\ManagementApi\Data\SpaceEnvironment;
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
            $this->provisionWorkflow($reporter, $spaceId, $config, $dryRun, $continueOnError);
            $this->provisionFolders($reporter, $spaceId, $config, $dryRun, $continueOnError);
            $this->provisionStoryMoves($reporter, $spaceId, $config, $dryRun, $continueOnError);
            $this->provisionApps($reporter, $spaceId, $config, $dryRun, $continueOnError);
            $this->provisionAi($reporter, $spaceId, $config, $dryRun, $continueOnError);
            $this->provisionAiTranslation($reporter, $spaceId, $config, $dryRun, $continueOnError);
            $this->provisionDimensions($reporter, $spaceId, $config, $dryRun, $continueOnError);
            $this->provisionAssets($reporter, $spaceId, $config, $configDirectory, $dryRun, $continueOnError);
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
