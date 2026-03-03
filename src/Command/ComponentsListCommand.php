<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Component\ComponentsListAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'components:list',
    description: 'List components with optional filters',
)]
class ComponentsListCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption(
                'search',
                null,
                InputOption::VALUE_REQUIRED,
                'Search components by name',
            )
            ->addOption(
                'root-only',
                null,
                InputOption::VALUE_NONE,
                'Only show root components (content types)',
            )
            ->addOption(
                'in-group',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter by component group UUID',
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $search */
        $search = $input->getOption('search');
        /** @var string|null $inGroup */
        $inGroup = $input->getOption('in-group');

        $result = (new ComponentsListAction($this->client))->execute(
            spaceId: $this->spaceId,
            search: $search,
            rootOnly: (bool) $input->getOption('root-only'),
            inGroup: $inGroup,
        );

        if ($result->count() === 0) {
            Render::log('No components found matching the filters');
            return self::SUCCESS;
        }

        Render::titleSection(
            'Components (' . $result->count() . ')',
        );

        /** @var \Storyblok\ManagementApi\Data\Component $component */
        foreach ($result->components as $component) {
            $details = [];
            $details[] = 'name: ' . $component->name();
            $type = $component->getComponentTypeDetail();
            if ($type !== '') {
                $details[] = $type;
            }

            Render::labelValue(
                $component->displayName() ?: $component->realName(),
                implode(' | ', $details),
            );
        }

        return self::SUCCESS;
    }
}
