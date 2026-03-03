<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Space\SpaceDemoRemoveAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\confirm;

#[AsCommand(
    name: 'space:demo-remove',
    description: 'Remove demo mode from a Storyblok space',
)]
class SpaceDemoRemoveCommand extends AbstractCommand
{
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $action = new SpaceDemoRemoveAction($this->client);
        $result = $action->preflight($this->spaceId);
        $space = $result->space;

        if (!$result->isDemo) {
            Render::log(
                'Space ' . $space->name() . ' is NOT in demo mode. Nothing to do.',
            );
            return self::SUCCESS;
        }

        Render::labelValueCondition(
            'Demo/example space mode?',
            $result->isDemo,
            'DEMO/Example space',
            'NOT a DEMO/Example space',
        );

        $noInteraction = $input->getOption('no-interaction');

        $removeFromDemo = $noInteraction || confirm(
            label: 'The space is in DEMO mode, do you want to remove the DEMO mode?',
            default: true,
            yes: 'YES',
            no: 'Keep it as DEMO',
            required: true,
            hint: 'The Space is in DEMO mode, to load the custom Preview URLs you should remove it from DEMO',
        );

        if (!$removeFromDemo) {
            Render::log('Keeping space in demo mode');
            return self::SUCCESS;
        }

        try {
            $action->execute($this->spaceId, $result);
            Render::log('Demo mode removed from space ' . $space->id());
        } catch (\Exception $exception) {
            Render::log('ERROR Saving space: ' . $exception->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
