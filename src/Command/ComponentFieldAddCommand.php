<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Component\ComponentFieldAddAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\text;

#[AsCommand(
    name: 'component:field-add',
    description: 'Add a field to a component inside a tab',
)]
class ComponentFieldAddCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption(
                'component',
                null,
                InputOption::VALUE_REQUIRED,
                'Component name (e.g. default-page)',
            )
            ->addOption(
                'field',
                null,
                InputOption::VALUE_REQUIRED,
                'Field name to add (e.g. SEO)',
            )
            ->addOption(
                'type',
                null,
                InputOption::VALUE_REQUIRED,
                'Field type: a core type (text, textarea, richtext, number, boolean, ...) or "custom" for plugins (default: custom)',
            )
            ->addOption(
                'field-type',
                null,
                InputOption::VALUE_REQUIRED,
                'Plugin field_type slug, required when --type=custom (e.g. sb-ai-seo)',
            )
            ->addOption(
                'tab',
                null,
                InputOption::VALUE_REQUIRED,
                'Tab display name to place the field in (e.g. SEO)',
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $componentName */
        $componentName = $input->getOption('component');
        if (empty($componentName) && !$input->getOption('no-interaction')) {
            $componentName = text(
                label: 'What is the component name?',
                placeholder: 'E.g. default-page',
                required: 'Component name is required.',
            );
        }

        if (empty($componentName)) {
            Render::error('--component is required.');
            return self::FAILURE;
        }

        /** @var string|null $fieldName */
        $fieldName = $input->getOption('field');
        if (empty($fieldName) && !$input->getOption('no-interaction')) {
            $fieldName = text(
                label: 'What is the field name?',
                placeholder: 'E.g. SEO',
                required: 'Field name is required.',
            );
        }

        if (empty($fieldName)) {
            Render::error('--field is required.');
            return self::FAILURE;
        }

        /** @var string|null $type */
        $type = $input->getOption('type');
        if (empty($type) && !$input->getOption('no-interaction')) {
            $type = text(
                label: 'What is the field type?',
                placeholder: 'E.g. text, textarea, richtext, number, boolean, custom',
                required: 'Field type is required.',
                hint: 'Use a core type (text, textarea, ...) or "custom" for plugin fields.',
            );
        }

        if (empty($type)) {
            $type = 'custom';
        }

        $isCustom = $type === 'custom';

        /** @var string|null $fieldType */
        $fieldType = $input->getOption('field-type');
        if ($isCustom) {
            if (empty($fieldType) && !$input->getOption('no-interaction')) {
                $fieldType = text(
                    label: 'What is the plugin field_type slug?',
                    placeholder: 'E.g. sb-ai-seo',
                    required: 'Plugin field_type is required for custom fields.',
                );
            }

            if (empty($fieldType)) {
                Render::error('--field-type is required when --type=custom.');
                return self::FAILURE;
            }
        }

        /** @var string|null $tabName */
        $tabName = $input->getOption('tab');
        if (empty($tabName) && !$input->getOption('no-interaction')) {
            $tabName = text(
                label: 'What is the tab display name?',
                placeholder: 'E.g. SEO',
                required: 'Tab name is required.',
            );
        }

        if (empty($tabName)) {
            Render::error('--tab is required.');
            return self::FAILURE;
        }

        $action = new ComponentFieldAddAction($this->client);

        try {
            $preflight = $action->preflight($this->spaceId, $componentName, $fieldName);
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        try {
            $action->execute(
                $this->spaceId,
                $preflight,
                $fieldName,
                $type,
                $tabName,
                $fieldType,
            );
            $typeLabel = $isCustom ? $fieldType : $type;
            Render::log(
                'Field "' . $fieldName . '" (' . $typeLabel . ') added to component "' . $componentName . '" in tab "' . $tabName . '"',
            );
        } catch (\Exception $exception) {
            Render::error('Failed to update component: ' . $exception->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
