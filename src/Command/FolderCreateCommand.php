<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Folder\FolderCreateAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\text;

#[AsCommand(
    name: 'folder:create',
    description: 'Create a folder',
)]
class FolderCreateCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'Folder name')
            ->addOption('parent-slug', null, InputOption::VALUE_REQUIRED, 'Parent folder slug (e.g. articles/archive)')
            ->addOption('parent-id', null, InputOption::VALUE_REQUIRED, 'Parent folder numeric ID (default: 0 for root)');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $name */
        $name = $input->getArgument('name');
        /** @var string|null $parentSlug */
        $parentSlug = $input->getOption('parent-slug');
        /** @var string|null $parentIdRaw */
        $parentIdRaw = $input->getOption('parent-id');

        if ($parentSlug && $parentIdRaw) {
            $output->writeln('<error>Provide only one of --parent-slug or --parent-id</error>');
            return self::FAILURE;
        }

        if (empty($name) && !$input->getOption('no-interaction')) {
            $name = text(
                label: 'Enter the folder name',
                placeholder: 'E.g. Articles, Archive',
                required: true,
            );
        }

        if (empty($name)) {
            $output->writeln('<error>Folder name is required</error>');
            return self::FAILURE;
        }

        $action = new FolderCreateAction($this->client);

        try {
            // Resolve parent folder
            $parentId = 0;
            if ($parentSlug !== null) {
                $parentId = $action->resolveParentBySlug($this->spaceId, $parentSlug);
            } elseif ($parentIdRaw !== null) {
                $parentId = (int) $parentIdRaw;
            }

            $result = $action->execute($this->spaceId, $name, $parentId);
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        Render::title('Folder Created');
        Render::labelValue('Name', $result->folder->name());
        Render::labelValue('Slug', $result->folder->slug());
        Render::labelValue('ID', $result->folder->id());
        if ($result->parentId > 0) {
            Render::labelValue('Parent folder ID', (string) $result->parentId);
        } else {
            Render::labelValue('Parent', 'Root');
        }

        return self::SUCCESS;
    }
}
