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
            $config = new SpaceSetupConfigLoader()->load($configPath);
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

        $output->writeln('<info>Space setup configuration is valid.</info>');
        return self::SUCCESS;
    }
}
