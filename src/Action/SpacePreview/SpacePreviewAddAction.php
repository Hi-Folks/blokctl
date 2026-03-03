<?php

declare(strict_types=1);

namespace Blokctl\Action\SpacePreview;

use Storyblok\ManagementApi\Data\Space;
use Storyblok\ManagementApi\Data\SpaceEnvironment;
use Storyblok\ManagementApi\Endpoints\SpaceApi;
use Storyblok\ManagementApi\ManagementApiClient;

final readonly class SpacePreviewAddAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    public function preflight(string $spaceId): SpacePreviewAddResult
    {
        $space = (new SpaceApi($this->client))->get($spaceId)->data();

        return new SpacePreviewAddResult(
            space: $space,
        );
    }

    /**
     * Add a preview environment to the space.
     */
    public function execute(
        string $spaceId,
        SpacePreviewAddResult $preflight,
        string $name,
        string $url,
    ): void {
        $editSpace = new Space($preflight->space->name());
        $editSpace->set('id', $preflight->space->id());
        $editSpace->addEnvironment(new SpaceEnvironment($name, $url));

        (new SpaceApi($this->client))->update($spaceId, $editSpace);
    }
}
