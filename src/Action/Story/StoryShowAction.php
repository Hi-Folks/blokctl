<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

use Storyblok\ManagementApi\Endpoints\StoryApi;
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\QueryParameters\StoriesParams;

final readonly class StoryShowAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * Fetch a story by ID, slug, or UUID.
     *
     * @throws \RuntimeException if the story is not found
     */
    public function execute(
        string $spaceId,
        ?string $id = null,
        ?string $slug = null,
        ?string $uuid = null,
    ): StoryShowResult {
        $storyApi = new StoryApi($this->client, $spaceId);

        if ($id) {
            $response = $storyApi->get($id);
            if (!$response->isOk()) {
                throw new \RuntimeException('Story not found with ID: ' . $id);
            }
        } else {
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
            $storyId = (string) $rawId;
            $response = $storyApi->get($storyId);
        }

        return new StoryShowResult(
            story: $response->data(),
            fullResponse: $response->toArray(),
        );
    }
}
