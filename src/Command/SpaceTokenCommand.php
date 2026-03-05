<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Space\SpaceTokenAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'space:token',
    description: 'Retrieve the first preview access token from a Storyblok space',
)]
class SpaceTokenCommand extends AbstractCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addOption(
            'only-token',
            null,
            InputOption::VALUE_NONE,
            'Output only the token string (useful for scripting)',
        );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $result = (new SpaceTokenAction($this->client))->execute($this->spaceId);

        if ($result->token === null || $result->token === '') {
            if (!$input->getOption('only-token')) {
                Render::error('No preview access token found for this space.');
            }

            return self::FAILURE;
        }

        if ($input->getOption('only-token')) {
            $output->write($result->token);

            return self::SUCCESS;
        }

        Render::title(
            sprintf('SPACE: %s (%s)', $result->space->name(), $this->spaceId),
        );
        Render::labelValue('Preview Access Token', $result->token);

        return self::SUCCESS;
    }
}
