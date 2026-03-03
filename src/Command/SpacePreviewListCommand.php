<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\SpacePreview\SpacePreviewListAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'space:preview-list',
    description: 'List preview URLs and frontend environments for a Storyblok space',
)]
class SpacePreviewListCommand extends AbstractCommand
{
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $result = (new SpacePreviewListAction($this->client))
            ->execute($this->spaceId);

        Render::titleSection('Preview URLs / Frontend environments');
        Render::labelValue('Default', $result->defaultDomain);

        if (!$result->hasEnvironments()) {
            Render::log('No additional frontend environments configured');
            return self::SUCCESS;
        }

        Render::titleSection(
            'Environments (' . $result->environments->count() . ')',
        );
        /** @var \Storyblok\ManagementApi\Data\SpaceEnvironment $environment */
        foreach ($result->environments as $environment) {
            Render::labelValue($environment->name(), $environment->location());
        }

        return self::SUCCESS;
    }
}
