<?php

declare(strict_types=1);

namespace Blokctl\Action\Component;

use Storyblok\ManagementApi\Data\Component;
use Storyblok\ManagementApi\Data\Fields\Schema\FieldAsset;
use Storyblok\ManagementApi\Data\Fields\Schema\FieldBloks;
use Storyblok\ManagementApi\Data\Fields\Schema\FieldBoolean;
use Storyblok\ManagementApi\Data\Fields\Schema\FieldDatetime;
use Storyblok\ManagementApi\Data\Fields\Schema\FieldGeneric;
use Storyblok\ManagementApi\Data\Fields\Schema\FieldInterface;
use Storyblok\ManagementApi\Data\Fields\Schema\FieldMarkdown;
use Storyblok\ManagementApi\Data\Fields\Schema\FieldMultiasset;
use Storyblok\ManagementApi\Data\Fields\Schema\FieldMultilink;
use Storyblok\ManagementApi\Data\Fields\Schema\FieldNumber;
use Storyblok\ManagementApi\Data\Fields\Schema\FieldOption;
use Storyblok\ManagementApi\Data\Fields\Schema\FieldOptions;
use Storyblok\ManagementApi\Data\Fields\Schema\FieldPlugin;
use Storyblok\ManagementApi\Data\Fields\Schema\FieldRichtext;
use Storyblok\ManagementApi\Data\Fields\Schema\FieldSection;
use Storyblok\ManagementApi\Data\Fields\Schema\FieldTable;
use Storyblok\ManagementApi\Data\Fields\Schema\FieldText;
use Storyblok\ManagementApi\Data\Fields\Schema\FieldTextarea;
use Storyblok\ManagementApi\Endpoints\ComponentApi;
use Storyblok\ManagementApi\ManagementApiClient;

final readonly class ComponentFieldAddAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * Fetch the component and validate the field can be added.
     *
     * @throws \RuntimeException if component not found or field already exists
     */
    public function preflight(
        string $spaceId,
        string $componentName,
        string $fieldName,
    ): ComponentFieldAddResult {
        $componentApi = new ComponentApi($this->client, $spaceId);

        $components = $componentApi->all()->data();
        $targetComponent = null;
        /** @var Component $component */
        foreach ($components as $component) {
            if ($component->name() === $componentName) {
                $targetComponent = $component;
                break;
            }
        }

        if ($targetComponent === null) {
            throw new \RuntimeException(
                'Component "' . $componentName . '" not found.',
            );
        }

        $component = $componentApi->get($targetComponent->id())->data();
        $schema = $component->getSchema();

        if (array_key_exists($fieldName, $schema)) {
            throw new \RuntimeException(
                'Field "' . $fieldName . '" already exists in component "' . $componentName . '".',
            );
        }

        return new ComponentFieldAddResult(
            component: $component,
            schema: $schema,
        );
    }

    /**
     * Add a field to the component inside a tab.
     */
    public function execute(
        string $spaceId,
        ComponentFieldAddResult $preflight,
        string $fieldName,
        string $type,
        string $tabName,
        ?string $fieldType = null,
        ?int $pos = null,
        ?string $displayName = null,
        bool $required = false,
        bool $translatable = false,
    ): void {
        $component = $preflight->component;

        $nextPos = $component->maxPos() + 1;

        // Find or create the tab
        $existingTabKey = null;
        $schema = $component->getSchema();
        foreach ($schema as $key => $entry) {
            if (
                is_array($entry)
                && ($entry['type'] ?? '') === 'tab'
                && ($entry['display_name'] ?? '') === $tabName
            ) {
                $existingTabKey = (string) $key;
                break;
            }
        }

        if ($existingTabKey !== null) {
            /** @var string[] $keys */
            $keys = $schema[$existingTabKey]['keys'] ?? [];
            $keys[] = $fieldName;
            $schema[$existingTabKey]['keys'] = $keys;
        } else {
            $tabKey = 'tab-' . $this->generateUuid();
            $schema[$tabKey] = [
                'display_name' => $tabName,
                'keys' => [$fieldName],
                'pos' => $nextPos,
                'type' => 'tab',
            ];
            ++$nextPos;
        }

        $component->setSchema($schema);

        $field = $this->makeField(
            fieldName: $fieldName,
            type: $type,
            fieldType: $fieldType,
            displayName: $displayName,
            required: $required,
            translatable: $translatable,
        );

        if ($pos !== null) {
            $component->insertField($field, $pos);
        } else {
            $component->insertField($field, $nextPos);
        }

        new ComponentApi($this->client, $spaceId)
            ->update($component->id(), $component);
    }

    private function makeField(
        string $fieldName,
        string $type,
        ?string $fieldType,
        ?string $displayName,
        bool $required,
        bool $translatable,
    ): FieldInterface {
        $type = strtolower($type);

        $field = match ($type) {
            'text' => FieldText::make($fieldName),
            'textarea' => FieldTextarea::make($fieldName),
            'richtext' => FieldRichtext::make($fieldName),
            'markdown' => FieldMarkdown::make($fieldName),
            'number' => FieldNumber::make($fieldName),
            'datetime' => FieldDatetime::make($fieldName),
            'boolean' => FieldBoolean::make($fieldName),
            'option' => FieldOption::make($fieldName),
            'options' => FieldOptions::make($fieldName),
            'asset' => FieldAsset::make($fieldName),
            'multiasset' => FieldMultiasset::make($fieldName),
            'multilink' => FieldMultilink::make($fieldName),
            'table' => FieldTable::make($fieldName),
            'bloks' => FieldBloks::make($fieldName),
            'section' => FieldSection::make($fieldName),
            'custom', 'plugin' => $this->makePluginField($fieldName, $fieldType),
            default => FieldGeneric::make($fieldName, ['type' => $type]),
        };

        if (!$field instanceof FieldGeneric) {
            return $field;
        }

        if ($displayName !== null && $displayName !== '') {
            $field->setDisplayName($displayName);
        }

        if ($required) {
            $field->setRequired();
        }

        if ($translatable) {
            $field->setTranslatable();
        }

        return $field;
    }

    private function makePluginField(string $fieldName, ?string $fieldType): FieldPlugin
    {
        if ($fieldType === null || $fieldType === '') {
            throw new \InvalidArgumentException('fieldType is required for custom fields.');
        }

        return FieldPlugin::make($fieldName, ['options' => []])
            ->setFieldType($fieldType);
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
        );
    }
}
