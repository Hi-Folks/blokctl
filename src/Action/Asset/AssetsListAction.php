<?php

declare(strict_types=1);

namespace Blokctl\Action\Asset;

use Storyblok\ManagementApi\Endpoints\AssetApi;
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\QueryParameters\AssetsParams;
use Storyblok\ManagementApi\QueryParameters\PaginationParams;

final readonly class AssetsListAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    public function execute(
        string $spaceId,
        ?string $search = null,
        int $page = 1,
        int $perPage = 25,
    ): AssetsListResult {
        $params = new AssetsParams(
            search: $search,
        );

        $pagination = new PaginationParams(
            page: $page,
            perPage: $perPage,
        );

        $assets = (new AssetApi($this->client, $spaceId))
            ->page($params, $pagination)->data();

        return new AssetsListResult(
            assets: $assets,
        );
    }
}
