<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\AppProvision\AppProvisionListAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:provision-list',
    description: 'List installed apps in a Storyblok space',
)]
class AppProvisionListCommand extends AbstractCommand
{
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $result = (new AppProvisionListAction($this->client))
            ->execute($this->spaceId);

        if ($result->count() === 0) {
            Render::log('No installed apps found');
            return self::SUCCESS;
        }

        Render::titleSection('Installed Apps (' . $result->count() . ')');

        /** @var \Storyblok\ManagementApi\Data\AppProvision $provision */
        foreach ($result->provisions as $provision) {
            $details = [];
            $details[] = 'slug: ' . $provision->slug();
            $details[] = 'app ID: ' . $provision->appId();
            if ($provision->inSidebar()) {
                $details[] = 'sidebar';
            }

            if ($provision->inToolbar()) {
                $details[] = 'toolbar';
            }

            Render::labelValue(
                $provision->name(),
                implode(' | ', $details),
            );
        }

        return self::SUCCESS;
    }
}
