<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Render;
use Blokctl\SpaceSetup\SpaceSetupConfigValidator;
use Blokctl\SpaceSetup\SpaceSetupExporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'space:setup-export',
    description: 'Export components, datasources, roles, and workflows of a space as a space:setup config',
)]
class SpaceSetupExportCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('section', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Section to export: ' . implode(', ', SpaceSetupExporter::SECTIONS) . ' (repeatable; all when omitted)')
            ->addOption('component', 'c', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Component name to export (repeatable; all when omitted)')
            ->addOption('datasource', 'd', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Datasource slug to export (repeatable; all when omitted)')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'YAML file to write; prints the YAML when omitted')
            ->addOption('entries-dir', null, InputOption::VALUE_REQUIRED, 'Write datasource entries as CSV files in this directory (relative to --output) instead of inline')
            ->addOption('include-secrets', null, InputOption::VALUE_NONE, 'Keep secret values instead of replacing them with the placeholder')
            ->addOption('secret-name', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Name treated as secret (repeatable; replaces the list from BLOKCTL_SECRET_NAMES or the defaults)')
            ->addOption('secret-placeholder', null, InputOption::VALUE_REQUIRED, 'Text that replaces secret values (default from BLOKCTL_SECRET_PLACEHOLDER or ' . SpaceSetupExporter::DEFAULT_SECRET_PLACEHOLDER . ')');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var list<string> $sections */
        $sections = $input->getOption('section');
        /** @var list<string> $components */
        $components = $input->getOption('component');
        /** @var list<string> $datasources */
        $datasources = $input->getOption('datasource');
        /** @var string|null $file */
        $file = $input->getOption('output');
        /** @var string|null $entriesDirectory */
        $entriesDirectory = $input->getOption('entries-dir');

        if ($entriesDirectory !== null && $file === null) {
            Render::error('--entries-dir needs --output, because the CSV paths are relative to the config file.');
            return self::FAILURE;
        }

        // Progress goes to stderr, so printed YAML on stdout stays clean; -q hides it.
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $progress = $output->isQuiet() ? null : static function (string $message) use ($errorOutput): void {
            $errorOutput->writeln('<comment>' . $message . '</comment>');
        };

        try {
            $result = new SpaceSetupExporter($this->client, $progress, $this->secretNames($input), $this->secretPlaceholder($input))->export(
                spaceId: $this->spaceId,
                sections: $sections === [] ? SpaceSetupExporter::SECTIONS : $sections,
                components: $components,
                datasources: $datasources,
                entriesDirectory: $entriesDirectory,
                includeSecrets: (bool) $input->getOption('include-secrets'),
            );
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        $yaml = $result->toYaml(sprintf('Exported by blokctl space:setup-export from space %s on %s', $this->spaceId, date('Y-m-d')));
        $validation = new SpaceSetupConfigValidator()->validate($result->config);

        if ($file === null) {
            $output->write($yaml, false, OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);
        } else {
            $directory = dirname($file);
            if (file_put_contents($file, $yaml) === false) {
                Render::error('Unable to write ' . $file);
                return self::FAILURE;
            }

            foreach ($result->files as $path => $content) {
                $target = $directory . '/' . $path;
                if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0o755, true)) {
                    Render::error('Unable to create ' . dirname($target));
                    return self::FAILURE;
                }

                if (file_put_contents($target, $content) === false) {
                    Render::error('Unable to write ' . $target);
                    return self::FAILURE;
                }
            }

            if ($output->isQuiet()) {
                return $validation->isValid() ? self::SUCCESS : self::FAILURE;
            }

            Render::title('Space Setup Export');
            Render::labelValue('Space ID', $this->spaceId);
            Render::labelValue('Config', htmlspecialchars($file, ENT_QUOTES));
            foreach (['components' => 'Components', 'datasources' => 'Datasources', 'roles' => 'Roles', 'workflow' => 'Workflows'] as $key => $label) {
                $section = $result->config[$key] ?? [];
                $count = is_array($section) ? count(is_array($section['ensure'] ?? null) ? $section['ensure'] : $section) : 0;
                Render::labelValue($label, (string) $count);
            }

            if ($result->files !== []) {
                Render::labelValue('CSV files', (string) count($result->files));
            }

            foreach ($result->warnings as $warning) {
                Render::log(htmlspecialchars($warning, ENT_QUOTES));
            }
        }

        if (!$validation->isValid()) {
            foreach ($validation->errors as $error) {
                $errorOutput->writeln('Export does not validate: ' . $error);
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Secret names from --secret-name, else BLOKCTL_SECRET_NAMES (comma separated, e.g. in .env), else the defaults.
     *
     * @return list<string>
     */
    private function secretNames(InputInterface $input): array
    {
        /** @var list<string> $names */
        $names = $input->getOption('secret-name');
        if ($names === []) {
            $configured = $_ENV['BLOKCTL_SECRET_NAMES'] ?? getenv('BLOKCTL_SECRET_NAMES');
            $names = is_string($configured) ? explode(',', $configured) : [];
        }

        $names = array_values(array_filter(array_map(trim(...), $names), static fn(string $name): bool => $name !== ''));

        return $names === [] ? SpaceSetupExporter::DEFAULT_SECRET_NAMES : $names;
    }

    /**
     * Placeholder from --secret-placeholder, else BLOKCTL_SECRET_PLACEHOLDER (e.g. in .env), else the default.
     */
    private function secretPlaceholder(InputInterface $input): string
    {
        $placeholder = $input->getOption('secret-placeholder');
        if (!is_string($placeholder) || $placeholder === '') {
            $placeholder = $_ENV['BLOKCTL_SECRET_PLACEHOLDER'] ?? getenv('BLOKCTL_SECRET_PLACEHOLDER');
        }

        return is_string($placeholder) && $placeholder !== '' ? $placeholder : SpaceSetupExporter::DEFAULT_SECRET_PLACEHOLDER;
    }
}
