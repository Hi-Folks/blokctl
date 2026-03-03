<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Space\SpaceInfoAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'space:info',
    description: 'Display information about a Storyblok space',
)]
class SpaceInfoCommand extends AbstractCommand
{
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $result = (new SpaceInfoAction($this->client))->execute($this->spaceId);
        $space = $result->space;
        $user = $result->user;

        Render::title(
            sprintf('SPACE: %s (%s)', $space->name(), $this->spaceId),
        );
        Render::labelValue('Space ID', $space->id());
        Render::labelValue('Name', $space->name());
        Render::labelValue('Plan', $space->planDescription());
        Render::labelValue('Plan level', $space->planLevel());
        Render::labelValueCondition(
            'Space ownership',
            $result->isOwner,
            'You (' . $space->ownerId() . ') are the owner',
            'The owner is ' . $space->ownerId(),
        );
        Render::labelValueCondition(
            'Demo/example space mode?',
            $space->isDemo(),
            'DEMO/Example space',
            'NOT a DEMO/Example space',
        );

        Render::titleSection('Current user');
        Render::labelValue('ID', $user->id());
        Render::labelValue('User identifier', $user->userId());
        Render::labelValueCondition(
            'Has Organization',
            $user->hasOrganization(),
            $user->orgName(),
            'No organization',
        );
        Render::labelValueCondition(
            'Has Partner program',
            $user->hasPartner(),
            'Is a partner',
            'Not a partner',
        );

        Render::titleSection('Preview URLs / Frontend environments');
        Render::labelValue('Default', $space->domain());
        Render::labelValueCondition(
            'Custom additional preview URLs',
            $space->environments()->count() > 0,
            'Yes, ' . $space->environments()->count(),
        );
        /** @var \Storyblok\ManagementApi\Data\SpaceEnvironment $environment */
        foreach ($space->environments() as $environment) {
            Render::labelValue($environment->name(), $environment->location());
        }

        return self::SUCCESS;
    }
}
