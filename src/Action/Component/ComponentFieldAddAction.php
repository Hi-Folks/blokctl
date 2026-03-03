<?php

declare(strict_types=1);

namespace Blokctl\Action\Component;

use Storyblok\ManagementApi\Data\Component;
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
    ): void {
        $schema = $preflight->schema;
        $isCustom = $type === 'custom';

        // Calculate next position
        $maxPos = -1;
        foreach ($schema as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (isset($entry['pos']) && is_int($entry['pos']) && $entry['pos'] > $maxPos) {
                $maxPos = $entry['pos'];
            }
        }

        $nextPos = $maxPos + 1;

        // Check if a tab with the same display_name already exists
        $existingTabKey = null;
        foreach ($schema as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (
                isset($entry['type']) && $entry['type'] === 'tab'
                && isset($entry['display_name']) && $entry['display_name'] === $tabName
            ) {
                $existingTabKey = $key;
                break;
            }
        }

        if ($existingTabKey !== null && is_array($schema[$existingTabKey])) {
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

        // Add the field
        $fieldEntry = [
            'type' => $type,
            'pos' => $nextPos,
        ];
        if ($isCustom) {
            $fieldEntry['field_type'] = $fieldType;
            $fieldEntry['options'] = [];
        }

        $schema[$fieldName] = $fieldEntry;

        $preflight->component->setSchema($schema);

        (new ComponentApi($this->client, $spaceId))
            ->update($preflight->component->id(), $preflight->component);
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
