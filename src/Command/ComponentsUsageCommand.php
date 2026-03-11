<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Component\ComponentsUsageAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'components:usage',
    description: 'Analyze component usage across all stories',
)]
class ComponentsUsageCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption(
                'starts-with',
                's',
                InputOption::VALUE_REQUIRED,
                'Filter by slug prefix (e.g. "blog/")',
            )
            ->addOption(
                'per-page',
                null,
                InputOption::VALUE_REQUIRED,
                'Results per page for API pagination (max 100)',
                '25',
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $startsWith */
        $startsWith = $input->getOption('starts-with');
        /** @var string $perPageOption */
        $perPageOption = $input->getOption('per-page');

        $result = (new ComponentsUsageAction($this->client))->execute(
            spaceId: $this->spaceId,
            region: $this->region,
            startsWith: $startsWith,
            perPage: (int) $perPageOption,
        );

        if ($result->count() === 0) {
            Render::log('No components found in stories');
            return self::SUCCESS;
        }

        Render::titleSection(
            'Component usage (' . $result->count() .
            ' components in ' . $result->storiesAnalyzed . ' stories)',
        );

        foreach ($result->usage as $componentName => $counts) {
            Render::labelValue(
                $componentName,
                $counts['stories'] . ' stories, ' . $counts['total'] . ' total',
            );
        }

        return self::SUCCESS;
    }
}
