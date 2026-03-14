<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

use Storyblok\ManagementApi\Data\Story;
use Storyblok\ManagementApi\Endpoints\StoryApi;
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\QueryParameters\StoriesParams;

final readonly class StoryMoveAction
{
    public function __construct(private ManagementApiClient $client) {}

    /**
     * Resolve a folder by its slug using the Management API.
     *
     * @return int The folder ID
     *
     * @throws \RuntimeException if the folder is not found
     */
    public function resolveFolderBySlug(
        string $spaceId,
        string $folderSlug,
    ): int {
        $params = new StoriesParams(folderOnly: true, withSlug: $folderSlug);

        $response = new StoryApi($this->client, $spaceId)->page($params);

        $folders = $response->data();

        if (count($folders) !== 1) {
            throw new \RuntimeException(
                "Folder not found with slug: " . $folderSlug,
            );
        }

        /** @var array{id: int} $folder */
        $folder = $folders[0];

        return (int) $folder["id"];
    }

    /**
     * Move a story to a different folder.
     *
     * @throws \RuntimeException if the story or folder is not found
     */
    public function execute(
        string $spaceId,
        int $folderId,
        ?string $storyId = null,
        ?string $storySlug = null,
    ): StoryMoveResult {
        $storyApi = new StoryApi($this->client, $spaceId);

        // Resolve story ID from slug if needed
        if ($storySlug !== null && $storyId === null) {
            $params = new StoriesParams(withSlug: $storySlug);
            $stories = $storyApi->page($params)->data();

            if (count($stories) !== 1) {
                throw new \RuntimeException(
                    "Story not found with slug: " . $storySlug,
                );
            }

            /** @var array{id: int|string} $story */
            $story = $stories[0];
            $storyId = (string) $story["id"];
        }

        if ($storyId === null) {
            throw new \RuntimeException("Provide either a story ID or slug.");
        }

        // Fetch the story via MAPI
        $response = $storyApi->get($storyId);
        if (!$response->isOk()) {
            throw new \RuntimeException("Story not found with ID: " . $storyId);
        }

        $previousFolderId = $response->data()->folderId();
        $previousFullSlug = $response->data()->fullSlug();

        // Move story by updating the folder via StoryApi
        $storyData = Story::make($response->data()->toArray());
        $storyData->setFolderId($folderId);

        $updateResponse = $storyApi->update($storyId, $storyData);

        if (!$updateResponse->isOk()) {
            throw new \RuntimeException(
                "Failed to move story: " . $updateResponse->getErrorMessage(),
            );
        }

        return new StoryMoveResult(
            story: $updateResponse->data(),
            previousFolderId: $previousFolderId,
            newFolderId: $folderId,
            previousFullSlug: $previousFullSlug,
        );
    }
}
