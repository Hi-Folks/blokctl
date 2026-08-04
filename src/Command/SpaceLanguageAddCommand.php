<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Space\SpaceLanguagesEnsureAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'space:language-add',
    description: 'Add language codes to a Storyblok space',
)]
class SpaceLanguageAddCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument(
            'languages',
            InputArgument::IS_ARRAY | InputArgument::REQUIRED,
            'Language codes to add, e.g. it de fr',
        );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var list<string> $languages */
        $languages = $input->getArgument('languages');

        try {
            $result = new SpaceLanguagesEnsureAction($this->client)->execute($this->spaceId, $languages);
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        Render::title($result->changed ? 'Space Languages Updated' : 'Space Languages Already Match');
        Render::labelValue('Space ID', $this->spaceId);
        Render::labelValue('Languages', implode(', ', $result->languages));
        if ($result->addedLanguages !== []) {
            Render::labelValue('Added', implode(', ', $result->addedLanguages));
        }

        return self::SUCCESS;
    }
}
