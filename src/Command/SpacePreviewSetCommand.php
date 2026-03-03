<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\SpacePreview\SpacePreviewSetAction;
use Blokctl\Render;
use Storyblok\ManagementApi\Data\SpaceEnvironment;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\confirm;

#[AsCommand(
    name: 'space:preview-set',
    description: 'Set the preview URLs for a Storyblok space',
)]
class SpacePreviewSetCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument(
            'preview-url',
            InputArgument::REQUIRED,
            'The default preview URL for the space',
        );
        $this->addOption(
            'environment',
            'e',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Additional frontend environment as "Name=URL" (repeatable)',
        );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $action = new SpacePreviewSetAction($this->client);
        $result = $action->preflight($this->spaceId);
        $space = $result->space;

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

        $noInteraction = $input->getOption('no-interaction');

        $wantPreviewUrl = $noInteraction || confirm(
            label: 'Do you want to set the DEMO Preview URL?',
            default: true,
            yes: 'Yes, replace them (with netlify and localhost previews)',
            no: 'No, please skip',
            hint: 'If you want to "exit" from Demo mode you have to set preview URLs',
        );

        if (!$wantPreviewUrl) {
            Render::log('Skipping preview URL changes');
            return self::SUCCESS;
        }

        /** @var string $previewUrl */
        $previewUrl = $input->getArgument('preview-url');
        Render::log('Setting Preview URL: ' . $previewUrl);

        $environments = [];
        /** @var string[] $envOptions */
        $envOptions = $input->getOption('environment');
        foreach ($envOptions as $env) {
            $parts = explode('=', $env, 2);
            if (count($parts) !== 2) {
                Render::log('Invalid environment format (expected Name=URL): ' . $env);
                continue;
            }

            [$name, $url] = $parts;
            $environments[] = new SpaceEnvironment($name, $url);
            Render::log('Adding environment: ' . $name . ' → ' . $url);
        }

        $saveChanges = $noInteraction || confirm(
            'Saving changes to Space ' . $space->id() . '?',
        );

        if (!$saveChanges) {
            return self::SUCCESS;
        }

        try {
            $action->execute($this->spaceId, $result, $previewUrl, $environments);
            Render::log('Saved space ' . $space->id());
        } catch (\Exception $exception) {
            Render::log('ERROR Saving space: ' . $exception->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
