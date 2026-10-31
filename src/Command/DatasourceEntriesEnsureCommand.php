<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Datasource\DatasourceEntriesCsv;
use Blokctl\Action\Datasource\DatasourceEntriesEnsureAction;
use Blokctl\Action\Datasource\DatasourceEntriesEnsureResult;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'datasource:entries-ensure',
    description: 'Create datasource entries, or update their values and dimension values',
)]
class DatasourceEntriesEnsureCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('datasource', 'd', InputOption::VALUE_REQUIRED, 'Datasource slug or ID')
            ->addOption('entry', 'e', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Entry as name=value (repeatable), or a single name with --dimension-value to keep the current value')
            ->addOption('dimension-value', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Dimension value for the single --entry as code=value, e.g. it=Rosso (repeatable)')
            ->addOption('csv', null, InputOption::VALUE_REQUIRED, 'CSV file with columns name, value, and optional dimension codes');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $datasource */
        $datasource = $input->getOption('datasource');
        if (empty($datasource)) {
            Render::error('Datasource is required. Provide it with --datasource (slug or ID).');
            return self::FAILURE;
        }

        /** @var list<string> $entryOptions */
        $entryOptions = $input->getOption('entry');
        /** @var string|null $csv */
        $csv = $input->getOption('csv');

        try {
            $entries = $csv === null ? [] : DatasourceEntriesCsv::load($csv);
            /** @var list<string> $dimensionOptions */
            $dimensionOptions = $input->getOption('dimension-value');
            if ($dimensionOptions !== [] && count($entryOptions) !== 1) {
                throw new \RuntimeException('--dimension-value needs exactly one --entry.');
            }

            foreach ($entryOptions as $option) {
                if (!str_contains($option, '=') && $dimensionOptions === []) {
                    throw new \RuntimeException('Invalid --entry "' . $option . '". Use name=value, or name alone with --dimension-value.');
                }

                [$name, $value] = array_pad(explode('=', $option, 2), 2, null);
                $entry = ['name' => trim((string) $name)];
                if ($value !== null) {
                    $entry['value'] = $value;
                }

                foreach ($dimensionOptions as $dimensionOption) {
                    if (!str_contains($dimensionOption, '=')) {
                        throw new \RuntimeException('Invalid --dimension-value "' . $dimensionOption . '". Use code=value, e.g. it=Rosso.');
                    }

                    [$code, $dimensionValue] = explode('=', $dimensionOption, 2);
                    $entry['dimensions'][trim($code)] = $dimensionValue;
                }

                $entries[] = $entry;
            }

            if ($entries === []) {
                throw new \RuntimeException('Provide entries with --entry name=value or --csv.');
            }

            $result = new DatasourceEntriesEnsureAction($this->client)->execute($this->spaceId, $datasource, $entries);
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        Render::title('Datasource Entries');
        Render::labelValue('Space ID', $this->spaceId);
        Render::labelValue('Datasource', htmlspecialchars($datasource, ENT_QUOTES));
        foreach ($result->entries as $entry) {
            if ($entry['status'] === DatasourceEntriesEnsureResult::UNCHANGED) {
                continue;
            }

            $detail = match ($entry['status']) {
                DatasourceEntriesEnsureResult::FAILED => 'failed: ' . $entry['message'],
                DatasourceEntriesEnsureResult::CREATED => 'created',
                default => 'updated: ' . implode(', ', $entry['changed']),
            };
            Render::labelValue(htmlspecialchars($entry['name'], ENT_QUOTES), htmlspecialchars($detail, ENT_QUOTES));
        }

        Render::labelValue('Created', (string) $result->count(DatasourceEntriesEnsureResult::CREATED));
        Render::labelValue('Updated', (string) $result->count(DatasourceEntriesEnsureResult::UPDATED));
        Render::labelValue('Unchanged', (string) $result->count(DatasourceEntriesEnsureResult::UNCHANGED));

        return $result->hasFailures() ? self::FAILURE : self::SUCCESS;
    }
}
