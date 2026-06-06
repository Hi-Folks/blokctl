<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

use Blokctl\Action\AppProvision\AppProvisionInstallAction;
use Blokctl\Action\Component\ComponentFieldAddAction;
use Blokctl\Action\Space\SpaceDemoRemoveAction;
use Blokctl\Action\SpacePreview\SpacePreviewSetAction;
use Blokctl\Action\Story\StoriesTagsAssignAction;
use Blokctl\Action\Story\StoriesWorkflowAssignAction;
use Storyblok\ManagementApi\Data\SpaceEnvironment;
use Storyblok\ManagementApi\ManagementApiClient;

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

        $this->provisionPreview($reporter, $spaceId, $config, $dryRun, $continueOnError);
        $this->provisionDemoMode($reporter, $spaceId, $config, $dryRun, $continueOnError);
        $this->provisionWorkflow($reporter, $spaceId, $config, $dryRun, $continueOnError);
        $this->provisionApps($reporter, $spaceId, $config, $dryRun, $continueOnError);
        $this->provisionComponentFields($reporter, $spaceId, $config, $dryRun, $continueOnError);
        $this->provisionTags($reporter, $spaceId, $config, $dryRun, $continueOnError);

        $reporter->finish();

        return $reporter;
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

                $environments = [];
                foreach ($environmentConfigs as $environment) {
                    if (!is_array($environment)) {
                        continue;
                    }

                    $name = $this->stringValue($environment['name'] ?? '');
                    $url = $this->stringValue($environment['url'] ?? '');
                    if ($name === '' || $url === '') {
                        throw new \RuntimeException('Each preview environment requires name and url.');
                    }

                    $environments[] = new SpaceEnvironment($name, $url);
                }

                if (!$dryRun) {
                    $action = new SpacePreviewSetAction($this->client);
                    $action->execute($spaceId, $action->preflight($spaceId), $defaultUrl, $environments);
                }

                return new SpaceSetupOperationResult(
                    SpaceSetupOperationStatus::Updated,
                    'Configure preview URLs',
                    $defaultUrl . ' (' . count($environments) . ' environments)',
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
        foreach ($this->stringListValue($apps['install'] ?? []) as $slug) {
            $reporter->run('Install app: ' . $slug, SpaceSetupOperationStatus::Installed, $continueOnError || $this->boolValue($apps['continue_on_error'] ?? false), function () use ($spaceId, $slug, $dryRun): ?SpaceSetupOperationResult {
                if (!$dryRun) {
                    $action = new AppProvisionInstallAction($this->client);
                    $action->execute($spaceId, $action->resolveBySlug($spaceId, $slug));
                }

                return null;
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

                $action = new ComponentFieldAddAction($this->client);
                $preflight = $action->preflight($spaceId, $componentName, $fieldName);
                $action->execute(
                    spaceId: $spaceId,
                    preflight: $preflight,
                    fieldName: $fieldName,
                    type: $type,
                    tabName: $tab,
                    fieldType: $this->nullableStringValue($field['field_type'] ?? $field['fieldType'] ?? null),
                    pos: $this->nullableIntValue($field['pos'] ?? null),
                    displayName: $this->nullableStringValue($field['display_name'] ?? $field['displayName'] ?? null),
                    required: $this->boolValue($field['required'] ?? false),
                    translatable: $this->boolValue($field['translatable'] ?? false),
                );

                return null;
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

                $result = new StoriesTagsAssignAction($this->client)->execute($spaceId, $storyIds, $storySlugs, $tags);
                if ($result->errors !== []) {
                    throw new \RuntimeException(implode(' | ', $result->errors));
                }

                return new SpaceSetupOperationResult(
                    SpaceSetupOperationStatus::Updated,
                    $label,
                    count($result->tagged) . ' stories tagged.',
                );
            });
        }
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
}
