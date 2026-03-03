<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\SpacePreview\SpacePreviewAddAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\confirm;

#[AsCommand(
    name: 'space:preview-add',
    description: 'Add a new preview environment URL to a Storyblok space',
)]
class SpacePreviewAddCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument(
            'name',
            InputArgument::REQUIRED,
            'Environment name (e.g. "Staging", "Local Development")',
        );
        $this->addArgument(
            'url',
            InputArgument::REQUIRED,
            'Environment URL',
        );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $action = new SpacePreviewAddAction($this->client);
        $result = $action->preflight($this->spaceId);
        $space = $result->space;

        Render::titleSection('Current preview environments');
        Render::labelValue('Default', $space->domain());
        /** @var \Storyblok\ManagementApi\Data\SpaceEnvironment $environment */
        foreach ($space->environments() as $environment) {
            Render::labelValue($environment->name(), $environment->location());
        }

        /** @var string $name */
        $name = $input->getArgument('name');
        /** @var string $url */
        $url = $input->getArgument('url');

        Render::log('Adding environment: ' . $name . ' → ' . $url);

        $noInteraction = $input->getOption('no-interaction');

        $saveChanges = $noInteraction || confirm(
            'Save changes to Space ' . $space->id() . '?',
        );

        if (!$saveChanges) {
            Render::log('Skipping, no changes saved');
            return self::SUCCESS;
        }

        try {
            $action->execute($this->spaceId, $result, $name, $url);
            Render::log('Saved space ' . $space->id());
        } catch (\Exception $exception) {
            Render::log('ERROR Saving space: ' . $exception->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
