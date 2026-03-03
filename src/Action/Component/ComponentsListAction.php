<?php

declare(strict_types=1);

namespace Blokctl\Action\Component;

use Storyblok\ManagementApi\Endpoints\ComponentApi;
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\QueryParameters\ComponentsParams;

final readonly class ComponentsListAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    public function execute(
        string $spaceId,
        ?string $search = null,
        bool $rootOnly = false,
        ?string $inGroup = null,
    ): ComponentsListResult {
        $params = new ComponentsParams(
            isRoot: $rootOnly ?: null,
            search: $search,
            inGroup: $inGroup,
        );

        $components = (new ComponentApi($this->client, $spaceId))
            ->all($params)->data();

        return new ComponentsListResult(
            components: $components,
        );
    }
}
