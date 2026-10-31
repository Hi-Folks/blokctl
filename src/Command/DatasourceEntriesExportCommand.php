<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Datasource\DatasourceEntriesExportAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'datasource:entries-export',
    description: 'Export the entries of a datasource as CSV (name, value, dimension codes)',
)]
class DatasourceEntriesExportCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('datasource', 'd', InputOption::VALUE_REQUIRED, 'Datasource slug or ID')
            ->addOption('dimension', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Dimension code to include (repeatable); all dimensions when omitted')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'File to write; prints the CSV when omitted');
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

        /** @var list<string> $dimensions */
        $dimensions = $input->getOption('dimension');
        /** @var string|null $file */
        $file = $input->getOption('output');

        try {
            $result = new DatasourceEntriesExportAction($this->client)->execute(
                $this->spaceId,
                $datasource,
                $dimensions === [] ? null : $dimensions,
            );
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        if ($file === null) {
            $output->write($result->toCsv(), false, OutputInterface::OUTPUT_RAW);
            return self::SUCCESS;
        }

        if (file_put_contents($file, $result->toCsv()) === false) {
            Render::error('Unable to write ' . $file);
            return self::FAILURE;
        }

        Render::log(sprintf('Exported %d entry(ies) with columns %s to %s', $result->count(), implode(', ', $result->header), $file));

        return self::SUCCESS;
    }
}
