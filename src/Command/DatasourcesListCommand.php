<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Datasource\DatasourceLookup;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'datasources:list',
    description: 'List the datasources of the space with their dimensions',
)]
class DatasourcesListCommand extends AbstractCommand
{
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        try {
            $datasources = new DatasourceLookup($this->client)->datasources($this->spaceId);
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        if ($datasources === []) {
            Render::log('No datasources found');
            return self::SUCCESS;
        }

        Render::title('Datasources (' . count($datasources) . ')');
        foreach ($datasources as $datasource) {
            $dimensions = [];
            foreach (DatasourceLookup::dimensions($datasource) as $code => $dimension) {
                $dimensions[] = $code . ' (' . $this->stringValue($dimension['name'] ?? '') . ')';
            }

            Render::titleSection($this->escape($datasource['name'] ?? ''));
            Render::labelValue('Slug', $this->escape($datasource['slug'] ?? ''));
            Render::labelValue('ID', $this->escape($datasource['id'] ?? ''));
            Render::labelValue('Dimensions', $this->escape($dimensions === [] ? 'none' : implode(', ', $dimensions)));
        }

        return self::SUCCESS;
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function escape(mixed $value): string
    {
        return htmlspecialchars($this->stringValue($value), ENT_QUOTES);
    }
}
