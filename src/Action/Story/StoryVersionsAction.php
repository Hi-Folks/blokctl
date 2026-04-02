<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

use Storyblok\ManagementApi\Endpoints\ManagementApi;
use Storyblok\ManagementApi\Endpoints\StoryApi;
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\QueryParameters\StoriesParams;

final readonly class StoryVersionsAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * Fetch versions for a story by ID, slug, or UUID.
     *
     * @throws \RuntimeException if the story is not found or the API call fails
     */
    public function execute(
        string $spaceId,
        ?string $id = null,
        ?string $slug = null,
        ?string $uuid = null,
        bool $showContent = false,
        int $page = 1,
        int $perPage = 25,
    ): StoryVersionsResult {
        $storyId = $this->resolveStoryId($spaceId, $id, $slug, $uuid);

        $api = new ManagementApi($this->client);

        $queryParams = [
            'by_story_id' => $storyId,
            'page' => $page,
            'per_page' => $perPage,
        ];

        if ($showContent) {
            $queryParams['show_content'] = 'true';
        }

        $response = $api->get(
            sprintf('spaces/%s/story_versions', $spaceId),
            $queryParams,
        );

        if (!$response->isOk()) {
            throw new \RuntimeException(
                'Failed to fetch story versions: ' . $response->getErrorMessage(),
            );
        }

        /** @var array<string, mixed> $data */
        $data = $response->toArray();

        /** @var array<int, array<string, mixed>> $versions */
        $versions = $data['story_versions'] ?? [];

        return new StoryVersionsResult(
            versions: $versions,
            storyId: $storyId,
        );
    }

    /**
     * Resolve a story ID from slug or UUID when not provided directly.
     */
    private function resolveStoryId(
        string $spaceId,
        ?string $id,
        ?string $slug,
        ?string $uuid,
    ): string {
        if ($id) {
            return $id;
        }

        $storyApi = new StoryApi($this->client, $spaceId);

        if ($uuid) {
            $stories = $storyApi
                ->page(new StoriesParams(byUuids: $uuid, storyOnly: true))
                ->data();
        } else {
            $stories = $storyApi
                ->page(new StoriesParams(storyOnly: true, withSlug: $slug))
                ->data();
        }

        if ($stories->count() === 0) {
            $label = $uuid ? 'UUID: ' . $uuid : 'slug: ' . $slug;
            throw new \RuntimeException('Story not found with ' . $label);
        }

        /** @var int|string $rawId */
        $rawId = $stories->get('0.id');

        return (string) $rawId;
    }
}
