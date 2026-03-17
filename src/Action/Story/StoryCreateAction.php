<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

use Storyblok\ManagementApi\Data\Story;
use Storyblok\ManagementApi\Data\StoryComponent;
use Storyblok\ManagementApi\Endpoints\StoryApi;
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\QueryParameters\StoriesParams;

final readonly class StoryCreateAction
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
     * Create a story with content.
     *
     * @param array<string, mixed> $content Content fields including "component" key
     *
     * @throws \RuntimeException if creation fails or content is invalid
     */
    public function execute(
        string $spaceId,
        string $name,
        array $content,
        ?string $slug = null,
        int $parentId = 0,
        bool $publish = false,
    ): StoryCreateResult {
        if (!isset($content['component']) || !is_string($content['component'])) {
            throw new \RuntimeException(
                'Content must include a "component" key with the component name (e.g. "page", "article")',
            );
        }

        // Resolve _asset markers and bloks
        $resolver = new ContentResolver($this->client, $spaceId);
        $content = $resolver->resolve($content);

        $storyComponent = StoryComponent::make($content);
        $storySlug = $slug ?? $this->slugify($name);

        $story = new Story($name, $storySlug, $storyComponent);

        if ($parentId > 0) {
            $story->setFolderId($parentId);
        }

        $storyApi = new StoryApi($this->client, $spaceId);
        $response = $storyApi->create($story, publish: $publish);

        if (!$response->isOk()) {
            throw new \RuntimeException(
                'Failed to create story: ' . $response->getErrorMessage(),
            );
        }

        return new StoryCreateResult(
            story: $response->data(),
        );
    }

    /**
     * Parse a JSON string into a content array.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException if JSON is invalid
     */
    public function parseJson(string $json): array
    {
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $jsonException) {
            throw new \RuntimeException('Invalid JSON: ' . $jsonException->getMessage(), $jsonException->getCode(), $jsonException);
        }

        return $data;
    }

    /**
     * Read and parse a JSON file into a content array.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException if file cannot be read or JSON is invalid
     */
    public function parseJsonFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException('Content file not found: ' . $filePath);
        }

        $json = file_get_contents($filePath);
        if ($json === false) {
            throw new \RuntimeException('Failed to read content file: ' . $filePath);
        }

        return $this->parseJson($json);
    }

    private function slugify(string $name): string
    {
        $slug = mb_strtolower($name);
        $slug = (string) preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = (string) preg_replace('/[\s-]+/', '-', $slug);

        return trim($slug, '-');
    }
}
