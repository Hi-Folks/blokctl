<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Blokctl\Action\Component\ComponentShowAction;
use Blokctl\Render;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[AsCommand(
    name: 'component:show',
    description: 'Display fields and schema of a component by name or ID',
)]
class ComponentShowCommand extends AbstractCommand
{
    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption('by-name', null, InputOption::VALUE_REQUIRED, 'Find component by name (e.g. default-page)')
            ->addOption('by-id', null, InputOption::VALUE_REQUIRED, 'Find component by numeric ID')
            ->addOption('with-tabs', null, InputOption::VALUE_NONE, 'Also display tab information');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string|null $name */
        $name = $input->getOption('by-name');
        /** @var string|null $id */
        $id = $input->getOption('by-id');

        if ($name !== null && $id !== null) {
            $output->writeln('<error>Provide only one of --by-name or --by-id</error>');
            return self::FAILURE;
        }

        if ($name === null && $id === null && !$input->getOption('no-interaction')) {
            $method = (string) select(
                label: 'How do you want to find the component?',
                options: ['name' => 'By name', 'id' => 'By ID'],
                default: 'name',
            );
            $value = text(
                label: $method === 'name' ? 'Enter the component name' : 'Enter the component ID',
                placeholder: $method === 'name' ? 'E.g. default-page' : 'E.g. 123456',
                required: true,
            );
            if ($method === 'name') {
                $name = $value;
            } else {
                $id = $value;
            }
        }

        if ($name === null && $id === null) {
            $output->writeln('<error>Provide one of --by-name or --by-id</error>');
            return self::FAILURE;
        }

        try {
            $result = (new ComponentShowAction($this->client))->execute(
                spaceId: $this->spaceId,
                id: $id,
                name: $name,
            );
        } catch (\RuntimeException $runtimeException) {
            Render::error($runtimeException->getMessage());
            return self::FAILURE;
        }

        $component = $result->component;
        $fields = $component->getFields();
        $withTabs = (bool) $input->getOption('with-tabs');

        Render::title($component->displayName() ?: $component->realName());
        Render::labelValue('Name', $component->name());
        Render::labelValue('ID', $component->id());
        Render::labelValue('Type', $component->getComponentTypeDetail() ?: 'nestable');
        Render::titleSection('Fields (' . count($fields) . ')');

        foreach ($fields as $field) {
            $details = [$field->type()];

            if ($field->type() === 'custom') {
                $pluginSlug = $field->get('field_type');
                if (is_string($pluginSlug) && $pluginSlug !== '') {
                    $details[] = 'plugin: ' . $pluginSlug;
                }
            }

            $tab = $component->getFieldTab($field->key());
            if ($tab !== null) {
                $details[] = 'tab: ' . $tab;
            }

            $details[] = 'pos: ' . $field->pos();

            Render::labelValue($field->key(), implode(' | ', $details));
        }

        if ($withTabs) {
            $tabs = $component->getTabs();
            Render::titleSection('Tabs (' . count($tabs) . ')');
            foreach ($tabs as $tabKey => $tab) {
                /** @var string[] $keys */
                $keys = $tab['keys'] ?? [];
                $pos = isset($tab['pos']) && is_int($tab['pos']) ? $tab['pos'] : '?';
                $displayName = isset($tab['display_name']) && is_string($tab['display_name']) ? $tab['display_name'] : $tabKey;
                Render::labelValue(
                    $displayName,
                    'pos: ' . $pos . ' | fields: ' . implode(', ', $keys),
                );
            }
        }

        return self::SUCCESS;
    }
}
