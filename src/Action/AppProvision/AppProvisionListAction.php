<?php

declare(strict_types=1);

namespace Blokctl\Action\AppProvision;

use Storyblok\ManagementApi\Endpoints\AppProvisionApi;
use Storyblok\ManagementApi\ManagementApiClient;

final readonly class AppProvisionListAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    public function execute(string $spaceId): AppProvisionListResult
    {
        $provisions = (new AppProvisionApi($this->client, $spaceId))
            ->page()->data();

        return new AppProvisionListResult(
            provisions: $provisions,
        );
    }
}
