<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Component\ComponentFolderEnsureAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'component:folder-ensure',
    description: 'Create a component folder path in the block library, e.g. Marketing/Banners',
)]
class ComponentFolderEnsureCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'Folder path, e.g. Marketing/Banners');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $path */
        $path = $input->getOption('path');
        if (empty($path)) {
            Render::error('Folder path is required. Provide it with --path.');
            return self::FAILURE;
        }

        try {
            $result = new ComponentFolderEnsureAction($this->client)->execute($this->spaceId, $path);
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        Render::title($result->changed() ? 'Component Folder Created' : 'Component Folder Already Exists');
        Render::labelValue('Space ID', $this->spaceId);
        Render::labelValue('Path', htmlspecialchars($path, ENT_QUOTES));
        Render::labelValue('Folder UUID', $result->uuid());
        if ($result->created !== []) {
            Render::labelValue('Created', htmlspecialchars(implode(', ', $result->created), ENT_QUOTES));
        }

        return self::SUCCESS;
    }
}
