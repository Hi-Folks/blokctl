<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

use Storyblok\ManagementApi\Endpoints\StoryApi;
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\QueryParameters\StoriesParams;

final readonly class StoriesTagsAssignAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * Assign tags to stories identified by IDs and/or slugs.
     *
     * @param string[] $storyIds
     * @param string[] $storySlugs
     * @param string[] $tags
     */
    public function execute(
        string $spaceId,
        array $storyIds,
        array $storySlugs,
        array $tags,
        bool $merge = false,
    ): StoriesTagsAssignResult {
        $storyApi = new StoryApi($this->client, $spaceId);

        $resolvedIds = $storyIds;
        $errors = [];
        $tagged = [];
        $skipped = [];

        // Resolve slugs to IDs
        foreach ($storySlugs as $slug) {
            try {
                $stories = $storyApi
                    ->page(new StoriesParams(storyOnly: true, withSlug: $slug))
                    ->data();

                if ($stories->count() === 0) {
                    $errors[] = 'Story not found with slug: ' . $slug;
                    continue;
                }

                /** @var int|string $rawId */
                $rawId = $stories->get('0.id');
                $resolvedId = (string) $rawId;
                $resolvedIds[] = $resolvedId;
            } catch (\Exception $e) {
                $errors[] = 'Error resolving slug "' . $slug . '": ' . $e->getMessage();
                sleep(1);
            }
        }

        // Tag each story by ID
        foreach ($resolvedIds as $storyId) {
            try {
                $story = $storyApi->get($storyId)->data();
                $existingTags = array_values(array_filter(
                    $story->tagListAsArray(),
                    is_string(...),
                ));
                $targetTags = $merge
                    ? array_values(array_unique([...$existingTags, ...$tags]))
                    : $tags;

                if ($merge && $targetTags === $existingTags) {
                    $skipped[] = [
                        'name' => $story->name(),
                        'tags' => $story->tagListAsString(),
                    ];
                    continue;
                }

                $story->setTagsFromArray($targetTags);
                $storyEdited = $storyApi->update($storyId, $story)->data();
                $tagged[] = [
                    'name' => $storyEdited->name(),
                    'tags' => $storyEdited->tagListAsString(),
                ];
            } catch (\Exception $e) {
                $errors[] = 'Error tagging story ' . $storyId . ': ' . $e->getMessage();
                sleep(1);
            }
        }

        return new StoriesTagsAssignResult(
            tagged: $tagged,
            errors: $errors,
            skipped: $skipped,
        );
    }
}
