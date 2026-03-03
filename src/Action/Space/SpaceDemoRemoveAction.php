<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

use Storyblok\ManagementApi\Data\Space;
use Storyblok\ManagementApi\Endpoints\SpaceApi;
use Storyblok\ManagementApi\ManagementApiClient;

final readonly class SpaceDemoRemoveAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    public function preflight(string $spaceId): SpaceDemoRemoveResult
    {
        $space = (new SpaceApi($this->client))->get($spaceId)->data();

        return new SpaceDemoRemoveResult(
            space: $space,
            isDemo: $space->isDemo(),
        );
    }

    /**
     * Remove demo mode from the space.
     *
     * @throws \RuntimeException if the space is not in demo mode
     */
    public function execute(string $spaceId, SpaceDemoRemoveResult $preflight): void
    {
        if (!$preflight->isDemo) {
            throw new \RuntimeException(
                'Space "' . $preflight->space->name() . '" is not in demo mode.',
            );
        }

        $editSpace = new Space($preflight->space->name());
        $editSpace->set('id', $preflight->space->id());
        $editSpace->removeDemoMode();

        (new SpaceApi($this->client))->update($spaceId, $editSpace);
    }
}
