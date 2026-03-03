<?php

declare(strict_types=1);

namespace Blokctl\Action\SpacePreview;

use Storyblok\ManagementApi\Endpoints\SpaceApi;
use Storyblok\ManagementApi\ManagementApiClient;

final readonly class SpacePreviewListAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    public function execute(string $spaceId): SpacePreviewListResult
    {
        $space = (new SpaceApi($this->client))->get($spaceId)->data();

        return new SpacePreviewListResult(
            defaultDomain: $space->domain(),
            environments: $space->environments(),
        );
    }
}
