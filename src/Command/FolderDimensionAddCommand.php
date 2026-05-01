<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Folder\FolderDimensionAddAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\text;

#[AsCommand(
    name: 'folder:dimension-add',
    description: 'Create a folder and add it to the Dimensions app configuration',
)]
class FolderDimensionAddCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'Folder name')
            ->addOption(
                'ai-translation-code',
                null,
                InputOption::VALUE_REQUIRED,
                'AI translation language code for this dimension (e.g. it, fr, de). Defaults to empty string.',
                '',
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $name */
        $name = $input->getArgument('name');

        if (empty($name) && !$input->getOption('no-interaction')) {
            $name = text(
                label: 'Enter the folder name',
                placeholder: 'E.g. Italy, France, Global',
                required: true,
            );
        }

        if (empty($name)) {
            $output->writeln('<error>Folder name is required</error>');
            return self::FAILURE;
        }

        /** @var string $aiTranslationCode */
        $aiTranslationCode = $input->getOption('ai-translation-code') ?? '';

        $action = new FolderDimensionAddAction($this->client);

        try {
            $result = $action->execute($this->spaceId, $name, $aiTranslationCode);
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        Render::title('Folder Created and Added to Dimensions App');
        Render::labelValue('Name', $result->folder->name());
        Render::labelValue('Slug', $result->folder->slug());
        Render::labelValue('ID', $result->folder->id());
        Render::labelValue('Dimensions folder count', (string) count($result->folderIds));

        return self::SUCCESS;
    }
}
