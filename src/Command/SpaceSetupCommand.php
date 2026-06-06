<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\AppProvision\AppProvisionInstallAction;
use Blokctl\Action\Component\ComponentFieldAddAction;
use Blokctl\Action\Space\SpaceCreateAction;
use Blokctl\Action\Space\SpaceDemoRemoveAction;
use Blokctl\Action\Space\SpaceTokenAction;
use Blokctl\Action\SpacePreview\SpacePreviewSetAction;
use Blokctl\Action\Story\StoriesTagsAssignAction;
use Blokctl\Action\Story\StoriesWorkflowAssignAction;
use Blokctl\Render;
use Blokctl\SpaceSetup\SpaceSetupConfigLoader;
use Blokctl\SpaceSetup\SpaceSetupConfigValidator;
use Blokctl\SpaceSetup\SpaceSetupInputsResolver;
use Blokctl\SpaceSetup\SpaceSetupOperationResult;
use Blokctl\SpaceSetup\SpaceSetupOperationStatus;
use Blokctl\SpaceSetup\SpaceSetupReporter;
use Blokctl\SpaceSetup\SpaceSetupTargetResolver;
use Blokctl\SpaceSetup\SpaceSetupVariableResolver;
use Storyblok\ManagementApi\Data\Enum\Region;
use Storyblok\ManagementApi\Data\SpaceEnvironment;
use Storyblok\ManagementApi\ManagementApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'space:setup',
    description: 'Set up a Storyblok space from a JSON or YAML configuration file',
)]
class SpaceSetupCommand extends Command
{
    private ManagementApiClient $client;

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('space-id', 'S', InputOption::VALUE_REQUIRED, 'Existing Storyblok Space ID to set up')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'JSON or YAML setup configuration file')
            ->addOption('duplicate-from', null, InputOption::VALUE_REQUIRED, 'Create a new space by duplicating this source space ID before setup')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'New space name when using --duplicate-from')
            ->addOption('in-org', null, InputOption::VALUE_NONE, 'Create the duplicated space inside the current organization')
            ->addOption('demo', null, InputOption::VALUE_NONE, 'Mark the duplicated space as a demo/example space')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the planned setup without changing Storyblok')
            ->addOption('continue-on-error', null, InputOption::VALUE_NONE, 'Continue running setup steps after a non-fatal step failure')
            ->addOption('set', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Override a setup input as NAME=VALUE (repeatable)')
            ->addOption('region', 'R', InputOption::VALUE_REQUIRED, 'The Storyblok region (' . implode(', ', Region::values()) . ')');
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $token = $_ENV['SECRET_KEY'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('SECRET_KEY not found in environment. Check your .env file.');
        }

        /** @var string|null $regionValue */
        $regionValue = $input->getOption('region');
        $region = Region::EU;
        if ($regionValue !== null) {
            $region = Region::tryFrom(strtoupper($regionValue));
            if ($region === null) {
                throw new \RuntimeException('Invalid region "' . $regionValue . '". Valid regions: ' . implode(', ', Region::values()));
            }
        }

        $this->client = new ManagementApiClient($token, region: $region, shouldRetry: true);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            /** @var string|null $configPath */
            $configPath = $input->getOption('config');
            if ($configPath === null || $configPath === '') {
                Render::error('A setup configuration file is required. Provide it with --config.');
                return self::FAILURE;
            }

            $config = (new SpaceSetupConfigLoader())->load($configPath);
            $validation = (new SpaceSetupConfigValidator())->validate($config);
            if (!$validation->isValid()) {
                Render::error('Invalid space setup configuration:');
                foreach ($validation->errors as $error) {
                    Render::error($error);
                }

                return self::FAILURE;
            }

            /** @var string|null $spaceId */
            $spaceId = $input->getOption('space-id');
            /** @var string|null $duplicateFrom */
            $duplicateFrom = $input->getOption('duplicate-from');
            /** @var string|null $newSpaceName */
            $newSpaceName = $input->getOption('name');

            $dryRun = (bool) $input->getOption('dry-run');

            $spaceId = (new SpaceSetupTargetResolver())->resolve(
                existingSpaceId: $spaceId,
                duplicateFrom: $duplicateFrom,
                newSpaceName: $newSpaceName,
                dryRun: $dryRun,
                duplicate: fn(string $sourceSpaceId, string $name): string => (new SpaceCreateAction($this->client))->execute(
                    name: $name,
                    duplicateFrom: $sourceSpaceId,
                    isDemo: (bool) $input->getOption('demo'),
                    inOrg: (bool) $input->getOption('in-org'),
                )->space->id(),
            );

            /** @var string[] $inputOverrides */
            $inputOverrides = $input->getOption('set');
            $inputs = (new SpaceSetupInputsResolver())->resolve($config, $inputOverrides);
            $environment = getenv();
            if (!is_array($environment)) {
                $environment = [];
            }

            $resolver = new SpaceSetupVariableResolver([
                'inputs' => $inputs,
                'env' => array_merge($environment, $_ENV),
                'space' => [
                    'id' => $spaceId,
                    'preview_token' => $dryRun
                        ? 'PREVIEW_TOKEN'
                        : $this->resolvePreviewTokenWhenNeeded($config, $spaceId),
                ],
            ]);
            $config = $resolver->resolveConfig($config);

            $resolvedValidation = (new SpaceSetupConfigValidator())->validate($config);
            if (!$resolvedValidation->isValid()) {
                Render::error('Resolved space setup configuration is invalid:');
                foreach ($resolvedValidation->errors as $error) {
                    Render::error($error);
                }

                return self::FAILURE;
            }

            $continueOnError = (bool) $input->getOption('continue-on-error') || $this->boolValue($config['continue_on_error'] ?? false);
            $mode = $this->hasValue($duplicateFrom)
                ? 'Duplicate from ' . $duplicateFrom . ' as "' . $newSpaceName . '"'
                : 'Existing space';
            $this->runSetup($spaceId, $config, $dryRun, $continueOnError, $mode);
        } catch (\Exception $exception) {
            Render::error($exception->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function runSetup(
        string $spaceId,
        array $config,
        bool $dryRun,
        bool $continueOnError,
        string $mode,
    ): void {
        $reporter = new SpaceSetupReporter($dryRun);
        $reporter->start($spaceId, $mode);

        if ($this->sectionEnabled($config['preview'] ?? null)) {
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

        if ($this->boolValue($this->arrayValue($config['demo_mode'] ?? [])['remove'] ?? false)) {
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

        if ($this->boolValue($this->arrayValue($config['workflow'] ?? [])['assign_unstaged'] ?? false)) {
            $reporter->run('Assign workflow stages', SpaceSetupOperationStatus::Updated, $continueOnError, function () use ($spaceId, $config, $dryRun): ?SpaceSetupOperationResult {
                $workflow = $this->arrayValue($config['workflow'] ?? []);
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

                $result = (new StoriesTagsAssignAction($this->client))->execute($spaceId, $storyIds, $storySlugs, $tags);
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

        $reporter->finish();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolvePreviewTokenWhenNeeded(array $config, string $spaceId): string
    {
        $resolver = new SpaceSetupVariableResolver([]);
        if (!$resolver->containsExpression($config, 'space.preview_token')) {
            return '';
        }

        $token = (new SpaceTokenAction($this->client))->execute($spaceId)->token;
        if ($token === null || $token === '') {
            throw new \RuntimeException('Unable to resolve the preview token for space ' . $spaceId . '.');
        }

        return $token;
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

    private function hasValue(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }
}
