<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Space\SpaceCreateAction;
use Blokctl\Action\Space\SpaceTokenAction;
use Blokctl\Render;
use Blokctl\SpaceSetup\SpaceSetupConfigLoader;
use Blokctl\SpaceSetup\SpaceSetupConfigValidator;
use Blokctl\SpaceSetup\SpaceSetupInputsResolver;
use Blokctl\SpaceSetup\SpaceSetupProvisioner;
use Blokctl\SpaceSetup\SpaceSetupTargetResolver;
use Blokctl\SpaceSetup\SpaceSetupVariableResolver;
use Storyblok\ManagementApi\Data\Enum\Region;
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

            $config = new SpaceSetupConfigLoader()->load($configPath);
            $validation = new SpaceSetupConfigValidator()->validate($config);
            if (!$validation->isValid()) {
                Render::error('Invalid space setup configuration:');
                foreach ($validation->errors as $error) {
                    Render::error($error);
                }

                return self::FAILURE;
            }

            /** @var string|null $spaceId */
            $spaceId = $input->getOption('space-id');
            $dryRun = (bool) $input->getOption('dry-run');

            /** @var string[] $inputOverrides */
            $inputOverrides = $input->getOption('set');
            $inputs = new SpaceSetupInputsResolver()->resolve($config, $inputOverrides);
            $environment = getenv();
            if (!is_array($environment)) {
                $environment = [];
            }

            $baseVariables = [
                'inputs' => $inputs,
                'env' => array_merge($environment, $_ENV),
            ];
            $preflightConfig = new SpaceSetupVariableResolver([
                ...$baseVariables,
                'space' => [
                    'id' => SpaceSetupTargetResolver::DRY_RUN_SPACE_ID,
                    'preview_token' => 'PREVIEW_TOKEN',
                ],
            ])->resolveConfig($config);
            $preflightValidation = new SpaceSetupConfigValidator()->validate($preflightConfig);
            if (!$preflightValidation->isValid()) {
                Render::error('Resolved space setup configuration is invalid:');
                foreach ($preflightValidation->errors as $error) {
                    Render::error($error);
                }

                return self::FAILURE;
            }

            $spaceConfig = $this->arrayValue($preflightConfig['space'] ?? []);
            $duplicateFrom = $this->nullableStringValue($spaceConfig['duplicate_from'] ?? null);
            $newSpaceName = $this->nullableStringValue($spaceConfig['name'] ?? null);
            $spaceId = new SpaceSetupTargetResolver()->resolve(
                existingSpaceId: $spaceId,
                duplicateFrom: $duplicateFrom,
                newSpaceName: $newSpaceName,
                dryRun: $dryRun,
                duplicate: fn(string $sourceSpaceId, string $name): string => new SpaceCreateAction($this->client)->execute(
                    name: $name,
                    duplicateFrom: $sourceSpaceId,
                    isDemo: $this->boolValue($spaceConfig['demo'] ?? false),
                    inOrg: $this->boolValue($spaceConfig['in_org'] ?? false),
                )->space->id(),
            );

            $resolver = new SpaceSetupVariableResolver([
                ...$baseVariables,
                'space' => [
                    'id' => $spaceId,
                    'preview_token' => $dryRun
                        ? 'PREVIEW_TOKEN'
                        : $this->resolvePreviewTokenWhenNeeded($config, $spaceId),
                ],
            ]);
            $config = $resolver->resolveConfig($config);

            $resolvedValidation = new SpaceSetupConfigValidator()->validate($config);
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
            return $this->runSetup($spaceId, $config, $dryRun, $continueOnError, $mode)
                ? self::SUCCESS
                : self::FAILURE;
        } catch (\Exception $exception) {
            Render::error($exception->getMessage());
            return self::FAILURE;
        }
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
    ): bool {
        $reporter = new SpaceSetupProvisioner($this->client)->run(
            $spaceId,
            $config,
            $dryRun,
            $continueOnError,
            $mode,
        );

        return !$reporter->hasFailures();
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

        $token = new SpaceTokenAction($this->client)->execute($spaceId)->token;
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

    private function hasValue(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }
}
