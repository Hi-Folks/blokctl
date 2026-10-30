<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

use Storyblok\ManagementApi\Endpoints\StoryApi;
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\QueryParameters\StoriesParams;

/**
 * Resolve story or folder references (numeric ID or full slug) to numeric IDs.
 *
 * Full slugs are matched exactly: `products` is the folder, `products/` is the
 * folder's start page, `products/my-product` is a story.
 */
final readonly class StoryResolveIdAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * Resolve a single reference. Numeric references are returned as-is without an API call.
     *
     * @throws \RuntimeException if no story or folder matches the slug
     */
    public function execute(string $spaceId, string|int $reference): int
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            throw new \RuntimeException('Story or folder reference cannot be empty.');
        }

        if (ctype_digit($reference)) {
            return (int) $reference;
        }

        $stories = new StoryApi($this->client, $spaceId)
            ->page(new StoriesParams(withSlug: $reference))
            ->data()
            ->toArray();

        foreach ($stories as $story) {
            if (is_array($story) && ($story['full_slug'] ?? null) === $reference && is_numeric($story['id'] ?? null)) {
                return (int) $story['id'];
            }
        }

        throw new \RuntimeException('No story or folder found with slug: ' . $reference);
    }

    /**
     * Resolve several references, keeping their order and removing duplicate IDs.
     *
     * @param list<string|int> $references
     * @return list<int>
     */
    public function executeMany(string $spaceId, array $references): array
    {
        $ids = [];
        foreach ($references as $reference) {
            $ids[] = $this->execute($spaceId, $reference);
        }

        return array_values(array_unique($ids));
    }
}
