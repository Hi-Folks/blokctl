<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

use Storyblok\ManagementApi\Endpoints\SpaceApi;
use Storyblok\ManagementApi\Endpoints\UserApi;
use Storyblok\ManagementApi\ManagementApiClient;

final readonly class SpaceInfoAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    public function execute(string $spaceId): SpaceInfoResult
    {
        $space = (new SpaceApi($this->client))->get($spaceId)->data();
        $user = (new UserApi($this->client))->me()->data();

        return new SpaceInfoResult(
            space: $space,
            user: $user,
            isOwner: $space->isOwnedByUser($user),
        );
    }
}
