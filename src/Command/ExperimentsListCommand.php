<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Experiment\ExperimentsListAction;
use Blokctl\Render;
use Storyblok\ManagementApi\Data\Experiment;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'experiment:list',
    description: 'List experiments in a space',
)]
class ExperimentsListCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption(
                'page',
                'p',
                InputOption::VALUE_REQUIRED,
                'Page number',
                '1',
            )
            ->addOption(
                'per-page',
                null,
                InputOption::VALUE_REQUIRED,
                'Results per page',
                '25',
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string $pageOption */
        $pageOption = $input->getOption('page');
        /** @var string $perPageOption */
        $perPageOption = $input->getOption('per-page');

        try {
            $result = new ExperimentsListAction($this->client)->execute(
                spaceId: $this->spaceId,
                page: (int) $pageOption,
                perPage: (int) $perPageOption,
            );
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        if ($result->count() === 0) {
            Render::log('No experiments found');
            return self::SUCCESS;
        }

        $title = 'Experiments (page ' . $pageOption . ', showing ' . $result->count();
        if ($result->total !== null) {
            $title .= ' of ' . $result->total;
        }

        $title .= ')';

        Render::titleSection($title);

        /** @var Experiment $experiment */
        foreach ($result->experiments as $experiment) {
            $name = $experiment->displayName() !== ''
                ? $experiment->displayName()
                : $experiment->name();

            $details = [];
            $details[] = 'id: ' . $experiment->id();
            if ($experiment->status() !== '') {
                $details[] = 'status: ' . $experiment->status();
            }

            $details[] = 'stories: ' . count($experiment->storyIds());
            $details[] = 'variants: ' . count($experiment->experimentVariants());

            $updatedAt = $experiment->updatedAt();
            if ($updatedAt !== '') {
                $details[] = 'updated: ' . $updatedAt;
            }

            Render::labelValue($name, implode(' | ', $details));
        }

        return self::SUCCESS;
    }
}
