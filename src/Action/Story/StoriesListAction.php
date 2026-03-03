<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

use Storyblok\ManagementApi\Endpoints\StoryApi;
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\QueryParameters\PaginationParams;
use Storyblok\ManagementApi\QueryParameters\StoriesParams;

final readonly class StoriesListAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    public function execute(
        string $spaceId,
        ?string $contentType = null,
        ?string $startsWith = null,
        ?string $search = null,
        ?string $withTag = null,
        bool $publishedOnly = false,
        int $page = 1,
        int $perPage = 25,
    ): StoriesListResult {
        $params = new StoriesParams(
            containComponent: $contentType,
            withTag: $withTag,
            storyOnly: true,
            startsWith: $startsWith,
            search: $search,
            isPublished: $publishedOnly ?: null,
        );

        $pagination = new PaginationParams(
            page: $page,
            perPage: $perPage,
        );

        $stories = (new StoryApi($this->client, $spaceId))
            ->page($params, page: $pagination)->data();

        return new StoriesListResult(
            stories: $stories,
        );
    }
}
