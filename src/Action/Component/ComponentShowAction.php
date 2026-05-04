<?php

declare(strict_types=1);

namespace Blokctl\Action\Component;

use Storyblok\ManagementApi\Data\Component;
use Storyblok\ManagementApi\Endpoints\ComponentApi;
use Storyblok\ManagementApi\ManagementApiClient;

final readonly class ComponentShowAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * Fetch a component by ID or name.
     *
     * @throws \RuntimeException if the component is not found
     */
    public function execute(
        string $spaceId,
        ?string $id = null,
        ?string $name = null,
    ): ComponentShowResult {
        $componentApi = new ComponentApi($this->client, $spaceId);

        if ($id !== null) {
            $response = $componentApi->get($id);
            if (!$response->isOk()) {
                throw new \RuntimeException('Component not found with ID: ' . $id);
            }

            return new ComponentShowResult(component: $response->data());
        }

        $components = $componentApi->all()->data();
        $found = null;
        /** @var Component $component */
        foreach ($components as $component) {
            if ($component->name() === $name) {
                $found = $component;
                break;
            }
        }

        if ($found === null) {
            throw new \RuntimeException('Component not found with name: ' . $name);
        }

        $response = $componentApi->get($found->id());
        if (!$response->isOk()) {
            throw new \RuntimeException('Failed to fetch component "' . $name . '": ' . $response->getErrorMessage());
        }

        return new ComponentShowResult(component: $response->data());
    }
}
