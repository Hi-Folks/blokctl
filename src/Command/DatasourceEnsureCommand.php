<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Datasource\DatasourceEnsureAction;
use Blokctl\Action\Datasource\DatasourceEnsureResult;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'datasource:ensure',
    description: 'Create a datasource, or update its name and dimensions',
)]
class DatasourceEnsureCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('slug', null, InputOption::VALUE_REQUIRED, 'Datasource slug (the datasource is matched by slug)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Datasource name (defaults to the slug when creating)')
            ->addOption('dimension', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Dimension as code=Name, e.g. it=Italian (repeatable)');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $slug */
        $slug = $input->getOption('slug');
        if (empty($slug)) {
            Render::error('Datasource slug is required. Provide it with --slug.');
            return self::FAILURE;
        }

        /** @var string|null $name */
        $name = $input->getOption('name');
        /** @var list<string> $dimensionOptions */
        $dimensionOptions = $input->getOption('dimension');
        $dimensions = [];
        foreach ($dimensionOptions as $option) {
            [$code, $dimensionName] = array_pad(explode('=', $option, 2), 2, '');
            if (trim($code) === '') {
                Render::error('Invalid --dimension "' . $option . '". Use code=Name, e.g. it=Italian.');
                return self::FAILURE;
            }

            $dimensions[trim($code)] = trim($dimensionName);
        }

        try {
            $result = new DatasourceEnsureAction($this->client)->execute(
                spaceId: $this->spaceId,
                slug: $slug,
                name: $name,
                dimensions: $dimensions === [] ? null : $dimensions,
            );
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        Render::title(match ($result->status) {
            DatasourceEnsureResult::CREATED => 'Datasource Created',
            DatasourceEnsureResult::UPDATED => 'Datasource Updated',
            default => 'Datasource Already Matches',
        });
        Render::labelValue('Space ID', $this->spaceId);
        Render::labelValue('Datasource', htmlspecialchars($slug, ENT_QUOTES));
        Render::labelValue('Datasource ID', (string) $result->datasourceId());
        if ($result->changedFields !== []) {
            Render::labelValue('Changed', implode(', ', $result->changedFields));
        }

        return self::SUCCESS;
    }
}
