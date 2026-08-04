<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Space\SpaceLanguageCheckAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'space:language-list',
    description: 'List enabled language codes for a Storyblok space',
)]
class SpaceLanguageListCommand extends AbstractCommand
{
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        try {
            $languages = new SpaceLanguageCheckAction($this->client)->installedLanguages($this->spaceId);
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        Render::title('Space Languages');
        Render::labelValue('Space ID', $this->spaceId);
        Render::labelValue('Languages', $languages === [] ? '(none)' : implode(', ', $languages));

        return self::SUCCESS;
    }
}
