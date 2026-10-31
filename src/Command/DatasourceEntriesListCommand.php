<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Datasource\DatasourceLookup;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'datasource:entries-list',
    description: 'List the entries of a datasource, optionally with the values of one dimension',
)]
class DatasourceEntriesListCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('datasource', 'd', InputOption::VALUE_REQUIRED, 'Datasource slug or ID')
            ->addOption('dimension', null, InputOption::VALUE_REQUIRED, 'Dimension code (e.g. it) to show its values');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $datasource */
        $datasource = $input->getOption('datasource');
        /** @var string|null $code */
        $code = $input->getOption('dimension');
        if (empty($datasource)) {
            Render::error('Datasource is required. Provide it with --datasource (slug or ID).');
            return self::FAILURE;
        }

        $lookup = new DatasourceLookup($this->client);
        try {
            $data = $lookup->find($this->spaceId, $datasource);
            $dimensionId = null;
            if (!empty($code)) {
                $dimension = DatasourceLookup::dimensions($data)[$code] ?? null;
                if ($dimension === null) {
                    Render::error(sprintf('Dimension "%s" not found in datasource "%s".', $code, $datasource));
                    return self::FAILURE;
                }

                $dimensionId = is_numeric($dimension['id'] ?? null) ? (int) $dimension['id'] : null;
            }

            $entries = $lookup->entries($this->spaceId, is_numeric($data['id'] ?? null) ? (int) $data['id'] : 0, $dimensionId);
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        Render::title(sprintf('Entries of %s (%d)', htmlspecialchars($datasource, ENT_QUOTES), count($entries)));
        foreach ($entries as $entry) {
            $value = $this->stringValue($entry['value'] ?? '');
            if ($dimensionId !== null) {
                $value .= ' | ' . $code . ': ' . $this->stringValue($entry['dimension_value'] ?? '');
            }

            Render::labelValue(htmlspecialchars($this->stringValue($entry['name'] ?? ''), ENT_QUOTES), htmlspecialchars($value, ENT_QUOTES));
        }

        return self::SUCCESS;
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
