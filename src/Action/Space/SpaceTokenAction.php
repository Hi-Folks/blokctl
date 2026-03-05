<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

use Storyblok\ManagementApi\Endpoints\SpaceApi;
use Storyblok\ManagementApi\ManagementApiClient;

final readonly class SpaceTokenAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    public function execute(string $spaceId): SpaceTokenResult
    {
        $space = (new SpaceApi($this->client))->get($spaceId)->data();

        return new SpaceTokenResult(
            space: $space,
            token: $space->firstToken(),
        );
    }
}
