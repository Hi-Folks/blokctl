<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Component\ComponentEnsureAction;
use Blokctl\Action\Component\ComponentEnsureResult;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'component:ensure',
    description: 'Create a component, or update its settings (type, folder, display name, color, icon)',
)]
class ComponentEnsureCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Component technical name (the component is matched by name)')
            ->addOption('display-name', null, InputOption::VALUE_REQUIRED, 'Display name')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'content_type, nestable, or universal (nestable when creating)')
            ->addOption('folder', null, InputOption::VALUE_REQUIRED, 'Component folder path, e.g. Marketing/Banners (created when missing)')
            ->addOption('color', null, InputOption::VALUE_REQUIRED, 'Color, e.g. #ff0000')
            ->addOption('icon', null, InputOption::VALUE_REQUIRED, 'Icon name, e.g. block-buildin')
            ->addOption('preview-field', null, InputOption::VALUE_REQUIRED, 'Field shown as preview in the block list')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'Description');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $name */
        $name = $input->getOption('name');
        if (empty($name)) {
            Render::error('Component name is required. Provide it with --name.');
            return self::FAILURE;
        }

        try {
            $result = new ComponentEnsureAction($this->client)->execute(
                spaceId: $this->spaceId,
                name: $name,
                displayName: $this->stringOption($input, 'display-name'),
                type: $this->stringOption($input, 'type'),
                folder: $this->stringOption($input, 'folder'),
                color: $this->stringOption($input, 'color'),
                icon: $this->stringOption($input, 'icon'),
                previewField: $this->stringOption($input, 'preview-field'),
                description: $this->stringOption($input, 'description'),
            );
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        Render::title(match ($result->status) {
            ComponentEnsureResult::CREATED => 'Component Created',
            ComponentEnsureResult::UPDATED => 'Component Updated',
            default => 'Component Already Matches',
        });
        Render::labelValue('Space ID', $this->spaceId);
        Render::labelValue('Component', htmlspecialchars($name, ENT_QUOTES));
        Render::labelValue('Component ID', (string) $result->componentId());
        if ($result->changedFields !== []) {
            Render::labelValue('Changed', implode(', ', $result->changedFields));
        }

        if ($result->status === ComponentEnsureResult::CREATED) {
            Render::log('Add fields with component:field-add, or declare them in space:setup components.ensure.');
        }

        return self::SUCCESS;
    }

    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) ? $value : null;
    }
}
