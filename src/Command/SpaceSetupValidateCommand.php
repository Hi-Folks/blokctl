<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\SpaceSetup\SpaceSetupConfigLoader;
use Blokctl\SpaceSetup\SpaceSetupConfigValidator;
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
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string|null $configPath */
        $configPath = $input->getOption('config');
        if ($configPath === null || $configPath === '') {
            $output->writeln('<error>A setup configuration file is required. Provide it with --config.</error>');
            return self::FAILURE;
        }

        try {
            /** @var list<string> $overrides */
            $overrides = $input->getOption('set');
            $config = new SpaceSetupConfigLoader()->load($configPath, $overrides);
            $result = new SpaceSetupConfigValidator()->validate($config);
        } catch (\Exception $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return self::FAILURE;
        }

        if (!$result->isValid()) {
            $output->writeln('<error>Invalid space setup configuration:</error>');
            foreach ($result->errors as $error) {
                $output->writeln('  - ' . $error);
            }

            return self::FAILURE;
        }

        if ($input->getOption('print-merged')) {
            $output->write(Yaml::dump($config, 10, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_COMPACT_NESTED_MAPPING), false, OutputInterface::OUTPUT_RAW);
            return self::SUCCESS;
        }

        $output->writeln('<info>Space setup configuration is valid.</info>');
        return self::SUCCESS;
    }
}
