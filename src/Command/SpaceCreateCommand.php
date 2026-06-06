<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Space\SpaceCreateAction;
use Blokctl\Render;
use Storyblok\ManagementApi\Data\Enum\Region;
use Storyblok\ManagementApi\ManagementApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\text;

#[AsCommand(
    name: 'space:create',
    description: 'Create a new Storyblok space, optionally duplicated from an existing space',
)]
class SpaceCreateCommand extends Command
{
    private ManagementApiClient $client;

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'New space name')
            ->addOption(
                'name',
                null,
                InputOption::VALUE_REQUIRED,
                'New space name',
            )
            ->addOption(
                'duplicate-from',
                null,
                InputOption::VALUE_REQUIRED,
                'Existing space ID to duplicate',
            )
            ->addOption(
                'in-org',
                null,
                InputOption::VALUE_NONE,
                'Create the duplicated space inside the current organization',
            )
            ->addOption(
                'demo',
                null,
                InputOption::VALUE_NONE,
                'Mark the created space as a demo/example space',
            )
            ->addOption(
                'region',
                'R',
                InputOption::VALUE_REQUIRED,
                'The Storyblok region (' . implode(', ', Region::values()) . ')',
            )
            ->addOption(
                'only-id',
                null,
                InputOption::VALUE_NONE,
                'Output only the new space ID',
            );
    }

    protected function initialize(
        InputInterface $input,
        OutputInterface $output,
    ): void {
        $token = $_ENV['SECRET_KEY'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException(
                'SECRET_KEY not found in environment. Check your .env file.',
            );
        }

        /** @var string|null $regionValue */
        $regionValue = $input->getOption('region');
        $region = Region::EU;
        if ($regionValue !== null) {
            $region = Region::tryFrom(strtoupper($regionValue));
            if ($region === null) {
                throw new \RuntimeException(
                    'Invalid region "' . $regionValue . '". Valid regions: ' . implode(', ', Region::values()),
                );
            }
        }

        $this->client = new ManagementApiClient(
            $token,
            region: $region,
            shouldRetry: true,
        );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $name */
        $name = $input->getArgument('name');
        if (empty($name)) {
            /** @var string|null $nameOption */
            $nameOption = $input->getOption('name');
            $name = $nameOption;
        }

        /** @var string|null $duplicateFrom */
        $duplicateFrom = $input->getOption('duplicate-from');

        if (empty($name) && !$input->getOption('no-interaction')) {
            $name = text(
                label: 'What is the new space name?',
                placeholder: 'E.g. NEW SPACE FROM TEMPLATE',
                required: 'The new space name is required.',
            );
        }

        if (empty($name)) {
            Render::error('Space name is required. Provide it as an argument or run interactively.');
            return self::FAILURE;
        }

        try {
            $result = (new SpaceCreateAction($this->client))->execute(
                name: $name,
                duplicateFrom: $duplicateFrom,
                isDemo: (bool) $input->getOption('demo'),
                inOrg: (bool) $input->getOption('in-org'),
            );
        } catch (\Exception $exception) {
            Render::error($exception->getMessage());
            return self::FAILURE;
        }

        if ($input->getOption('only-id')) {
            $output->write($result->space->id());
            return self::SUCCESS;
        }

        Render::title($result->duplicated ? 'Space Duplicated' : 'Space Created');
        Render::labelValue('Name', $result->space->name());
        Render::labelValue('Space ID', $result->space->id());
        Render::labelValue('Domain', $result->space->domain());
        Render::labelValueCondition(
            'Demo/example space mode?',
            $result->space->isDemo(),
            'DEMO/Example space',
            'NOT a DEMO/Example space',
        );

        if ($result->duplicateFrom !== null && $result->duplicateFrom !== '') {
            Render::labelValue('Duplicated from', $result->duplicateFrom);
        }

        return self::SUCCESS;
    }
}
