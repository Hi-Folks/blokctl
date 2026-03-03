<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Storyblok\ManagementApi\Data\Enum\Region;
use Storyblok\ManagementApi\ManagementApiClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\text;

abstract class AbstractCommand extends Command
{
    protected ManagementApiClient $client;

    protected string $spaceId;

    protected function configure(): void
    {
        $this->addOption(
            'space-id',
            'S',
            InputOption::VALUE_REQUIRED,
            'The Storyblok Space ID',
        );
        $this->addOption(
            'region',
            'R',
            InputOption::VALUE_REQUIRED,
            'The Storyblok region (' . implode(', ', Region::values()) . ')',
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

        $spaceId = $input->getOption('space-id');
        if (empty($spaceId) && !$input->getOption('no-interaction')) {
            $spaceId = text(
                label: 'What is the Space ID?',
                placeholder: 'E.g. 288455164961039',
                required: 'Your Space ID is required.',
                validate: fn(string $value): ?string => match (true) {
                    strlen($value) < 6 => 'The spaceID must be at least 6 digits.',
                    strlen($value) > 16 => 'The spaceID must not exceed 16 digits (53 bits).',
                    default => null,
                },
                hint: 'This is the Space you want to use for the Demo.',
            );
        }

        if (empty($spaceId)) {
            throw new \RuntimeException(
                'Space ID is required. Provide it with --space-id (-S) or run interactively.',
            );
        }

        /** @var string $spaceId */
        $this->spaceId = $spaceId;
    }
}
