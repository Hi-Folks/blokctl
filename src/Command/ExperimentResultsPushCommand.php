<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Experiment\ExperimentResultsPushAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\text;

#[AsCommand(
    name: 'experiment:results:push',
    description: 'Push experiment result charts from a JSON file',
)]
class ExperimentResultsPushCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addArgument('experiment-id', InputArgument::OPTIONAL, 'Experiment ID')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Path to a JSON file with the experiment results payload');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $experimentId */
        $experimentId = $input->getArgument('experiment-id');
        /** @var string|null $file */
        $file = $input->getOption('file');

        if (empty($experimentId) && !$input->getOption('no-interaction')) {
            $experimentId = text(
                label: 'Enter the experiment ID',
                placeholder: 'E.g. 176070002766742',
                required: true,
            );
        }

        if (empty($experimentId)) {
            $output->writeln('<error>Experiment ID is required</error>');
            return self::FAILURE;
        }

        if (empty($file) && !$input->getOption('no-interaction')) {
            $file = text(
                label: 'Enter the experiment results JSON file',
                placeholder: 'E.g. examples/experiment-results.json',
                required: true,
            );
        }

        if (empty($file)) {
            $output->writeln('<error>Provide --file with the experiment results JSON path</error>');
            return self::FAILURE;
        }

        $action = new ExperimentResultsPushAction($this->client);

        try {
            $payload = $action->parseJsonFile($file);
            $result = $action->execute(
                spaceId: $this->spaceId,
                experimentId: $experimentId,
                payload: $payload,
            );
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        Render::title('Experiment Results Pushed');
        Render::labelValue('Experiment ID', $result->experimentResult->experimentId());
        Render::labelValue('Result ID', $result->experimentResult->id());
        Render::labelValue('Charts', (string) $result->chartCount());
        Render::labelValue('Pushed at', $result->experimentResult->pushedAt());

        return self::SUCCESS;
    }
}
