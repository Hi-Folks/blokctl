<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Datasource\DatasourceEntriesRemoveAction;
use Blokctl\Action\Datasource\DatasourceEntriesRemoveResult;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\confirm;

#[AsCommand(
    name: 'datasource:entries-remove',
    description: 'Delete datasource entries by name',
)]
class DatasourceEntriesRemoveCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('datasource', 'd', InputOption::VALUE_REQUIRED, 'Datasource slug or ID')
            ->addOption('entry', 'e', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Entry name to delete (repeatable)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the entries that would be deleted without changing the datasource');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $datasource */
        $datasource = $input->getOption('datasource');
        /** @var list<string> $names */
        $names = $input->getOption('entry');
        if (empty($datasource)) {
            Render::error('Datasource is required. Provide it with --datasource (slug or ID).');
            return self::FAILURE;
        }

        $action = new DatasourceEntriesRemoveAction($this->client);
        try {
            $preflight = $action->preflight($this->spaceId, $datasource, $names);
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        Render::title('Remove Datasource Entries');
        Render::labelValue('Space ID', $this->spaceId);
        Render::labelValue('Datasource', htmlspecialchars($datasource, ENT_QUOTES));
        $this->renderEntries($preflight);

        $planned = $preflight->entriesWithStatus(DatasourceEntriesRemoveResult::PLANNED);
        if ($planned === []) {
            Render::log('Nothing to delete.');
            return self::SUCCESS;
        }

        if ($input->getOption('dry-run')) {
            Render::log('Dry run: no changes made.');
            return self::SUCCESS;
        }

        $confirmed = (bool) $input->getOption('no-interaction') || confirm(
            label: sprintf('Delete %d entry(ies)? This cannot be undone.', count($planned)),
            default: false,
        );
        if (!$confirmed) {
            Render::log('Cancelled.');
            return self::SUCCESS;
        }

        $result = $action->execute($this->spaceId, $preflight);
        Render::titleSection('Result');
        $this->renderEntries($result);

        return $result->hasFailures() ? self::FAILURE : self::SUCCESS;
    }

    private function renderEntries(DatasourceEntriesRemoveResult $result): void
    {
        foreach ($result->entries as $entry) {
            $status = match ($entry['status']) {
                DatasourceEntriesRemoveResult::PLANNED => 'will be deleted',
                DatasourceEntriesRemoveResult::NOT_FOUND => 'not found, skipped',
                DatasourceEntriesRemoveResult::REMOVED => 'deleted',
                default => 'failed: ' . $entry['message'],
            };
            Render::labelValue(htmlspecialchars($entry['name'], ENT_QUOTES), htmlspecialchars($status, ENT_QUOTES));
        }
    }
}
