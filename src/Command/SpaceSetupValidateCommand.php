<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\ManagementAccessToken;
use Blokctl\SpaceSetup\SpaceSetupConfigLoader;
use Blokctl\SpaceSetup\SpaceSetupConfigValidator;
use Blokctl\SpaceSetup\SpaceSetupContentChecker;
use Blokctl\SpaceSetup\SpaceSetupInputsResolver;
use Blokctl\SpaceSetup\SpaceSetupValueMasker;
use Storyblok\ManagementApi\Data\Enum\Region;
use Storyblok\ManagementApi\ManagementApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'space:setup-validate',
    description: 'Validate a Storyblok space setup JSON or YAML configuration file',
)]
class SpaceSetupValidateCommand extends Command
{
    #[\Override]
    protected function configure(): void
    {
        $this->addOption(
            'config',
            'c',
            InputOption::VALUE_REQUIRED,
            'JSON or YAML setup configuration file',
        );
        $this->addOption(
            'set',
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Input value as NAME=VALUE, used by extends conditions (repeatable)',
        );
        $this->addOption(
            'print-merged',
            null,
            InputOption::VALUE_NONE,
            'Print the configuration after merging the files listed in extends',
        );
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print a machine-readable validation result');
        $this->addOption('space-id', 'S', InputOption::VALUE_REQUIRED, 'Also validate content against components in this existing space (read-only)');
        $this->addOption('region', 'R', InputOption::VALUE_REQUIRED, 'Storyblok region (' . implode(', ', Region::values()) . ')');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $masker = new SpaceSetupValueMasker();
        /** @var string|null $configPath */
        $configPath = $input->getOption('config');
        if ($configPath === null || $configPath === '') {
            $this->writeResult($output, (bool) $input->getOption('json'), false, ['A setup configuration file is required. Provide it with --config.'], masker: $masker);
            return self::FAILURE;
        }

        try {
            /** @var list<string> $overrides */
            $overrides = $input->getOption('set');
            $config = new SpaceSetupConfigLoader()->load($configPath, $overrides);
            $result = new SpaceSetupConfigValidator()->validate($config);
        } catch (\Exception $exception) {
            $this->writeResult($output, (bool) $input->getOption('json'), false, [$exception->getMessage()], masker: $masker);
            return self::FAILURE;
        }

        if (!$result->isValid()) {
            $this->writeResult($output, (bool) $input->getOption('json'), false, array_values($result->errors), masker: $masker);
            return self::FAILURE;
        }

        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }

        $inputResult = new SpaceSetupInputsResolver()->resolveWithSecrets(
            $config,
            $overrides,
            array_merge($environment, $_ENV),
            false,
        );
        $masker = new SpaceSetupValueMasker($inputResult->secretValues);

        try {
            $checker = new SpaceSetupContentChecker($config);
            /** @var string|null $spaceId */
            $spaceId = $input->getOption('space-id');
            if ($checker->hasContentToCheck() && $spaceId !== null && $spaceId !== '') {
                $checker->withSpace($this->client($input), $spaceId);
            }

            $realPath = realpath($configPath);
            $contentResult = $checker->check(dirname($realPath === false ? $configPath : $realPath));
        } catch (\Exception $exception) {
            $this->writeResult($output, (bool) $input->getOption('json'), false, [$exception->getMessage()], masker: $masker);
            return self::FAILURE;
        }

        $warnings = array_map(
            static fn(string $component): string => sprintf('Component "%s" was not checked; declare it in the config or pass --space-id.', $component),
            $contentResult->uncheckedComponents,
        );
        if (!$contentResult->isValid()) {
            $this->writeResult($output, (bool) $input->getOption('json'), false, $contentResult->errors, $warnings, masker: $masker);
            return self::FAILURE;
        }

        if ((bool) $input->getOption('json')) {
            $this->writeResult(
                $output,
                true,
                true,
                warnings: $warnings,
                config: $input->getOption('print-merged') ? $config : null,
                masker: $masker,
            );
            return self::SUCCESS;
        }

        if ($input->getOption('print-merged')) {
            /** @var array<string, mixed> $maskedConfig */
            $maskedConfig = $masker->maskValue($config);
            $output->write(Yaml::dump($maskedConfig, 10, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_COMPACT_NESTED_MAPPING), false, OutputInterface::OUTPUT_RAW);
            return self::SUCCESS;
        }

        foreach ($warnings as $warning) {
            $output->writeln('<comment>Warning: ' . $warning . '</comment>');
        }

        $output->writeln('<info>Space setup configuration is valid.</info>');
        return self::SUCCESS;
    }

    private function client(InputInterface $input): ManagementApiClient
    {
        /** @var string|null $regionValue */
        $regionValue = $input->getOption('region');
        $region = $regionValue === null ? Region::EU : Region::tryFrom(strtoupper($regionValue));
        if ($region === null) {
            throw new \RuntimeException('Invalid region "' . $regionValue . '". Valid regions: ' . implode(', ', Region::values()));
        }

        return new ManagementApiClient(ManagementAccessToken::fromEnvironment($_ENV), region: $region, shouldRetry: true);
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     * @param array<string, mixed>|null $config
     */
    private function writeResult(
        OutputInterface $output,
        bool $asJson,
        bool $valid,
        array $errors = [],
        array $warnings = [],
        array|null $config = null,
        SpaceSetupValueMasker|null $masker = null,
    ): void {
        $masker ??= new SpaceSetupValueMasker();
        /** @var list<string> $errors */
        $errors = $masker->maskValue($errors);
        /** @var list<string> $warnings */
        $warnings = $masker->maskValue($warnings);
        /** @var array<string, mixed>|null $config */
        $config = $masker->maskValue($config);
        if ($asJson) {
            $payload = ['valid' => $valid, 'errors' => $errors, 'warnings' => $warnings];
            if ($config !== null) {
                $payload['config'] = $config;
            }

            $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            return;
        }

        if (!$valid) {
            $output->writeln('<error>Invalid space setup configuration:</error>');
            foreach ($errors as $error) {
                $output->writeln('  - ' . $error);
            }
        }
    }
}
