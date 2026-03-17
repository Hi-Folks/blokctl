<?php

declare(strict_types=1);

namespace Blokctl\Action\Folder;

use Storyblok\ManagementApi\Data\Story;
use Storyblok\ManagementApi\Data\StoryComponent;
use Storyblok\ManagementApi\Endpoints\StoryApi;
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\QueryParameters\StoriesParams;

final readonly class FolderCreateAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * Resolve a parent folder ID from its slug.
     *
     * @throws \RuntimeException if the folder is not found
     */
    public function resolveParentBySlug(
        string $spaceId,
        string $folderSlug,
    ): int {
        $params = new StoriesParams(folderOnly: true, withSlug: $folderSlug);
        $folders = (new StoryApi($this->client, $spaceId))->page($params)->data();

        if (count($folders) !== 1) {
            throw new \RuntimeException(
                'Parent folder not found with slug: ' . $folderSlug,
            );
        }

        /** @var array{id: int} $folder */
        $folder = $folders[0];

        return (int) $folder['id'];
    }

    /**
     * Create a folder.
     *
     * @throws \RuntimeException if the creation fails
     */
    public function execute(
        string $spaceId,
        string $name,
        int $parentId = 0,
    ): FolderCreateResult {
        $storyApi = new StoryApi($this->client, $spaceId);

        $folder = new Story($name, $this->slugify($name), new StoryComponent('folder'));
        $folder->set('is_folder', true);

        if ($parentId > 0) {
            $folder->setFolderId($parentId);
        }

        $response = $storyApi->create($folder);

        if (!$response->isOk()) {
            throw new \RuntimeException(
                'Failed to create folder: ' . $response->getErrorMessage(),
            );
        }

        return new FolderCreateResult(
            folder: $response->data(),
            parentId: $parentId,
        );
    }

    private function slugify(string $name): string
    {
        $slug = mb_strtolower($name);
        $slug = (string) preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = (string) preg_replace('/[\s-]+/', '-', $slug);

        return trim($slug, '-');
    }
}
