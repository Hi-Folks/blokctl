<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

use Storyblok\ManagementApi\Data\Story;
use Storyblok\ManagementApi\Data\StoryComponent;
use Storyblok\ManagementApi\Endpoints\StoryApi;
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\QueryParameters\StoriesParams;

final readonly class StoryUpdateAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * Update a story's content using simplified JSON with _asset and component conventions.
     *
     * @param array<string, mixed> $content Simplified content (with _asset markers and component bloks)
     *
     * @throws \RuntimeException if the story is not found or the update fails
     */
    public function execute(
        string $spaceId,
        array $content,
        ?string $storySlug = null,
        ?string $storyId = null,
        bool $publish = false,
    ): StoryUpdateResult {
        $storyApi = new StoryApi($this->client, $spaceId);
        $storyId = $this->resolveStoryId($storyApi, $storySlug, $storyId);

        // Fetch the existing story
        $response = $storyApi->get($storyId);
        if (!$response->isOk()) {
            throw new \RuntimeException('Story not found with ID: ' . $storyId);
        }

        // Resolve _asset markers and bloks
        $resolver = new ContentResolver($this->client, $spaceId);
        $resolvedContent = $resolver->resolve($content);

        // Merge resolved content into existing story content
        $storyData = Story::make($response->data()->toArray());
        $existingContent = $storyData->content()->toArray();

        foreach ($resolvedContent as $key => $value) {
            $existingContent[$key] = $value;
        }

        $storyData->setContent(StoryComponent::make($existingContent));

        // Update the story
        $updateResponse = $storyApi->update($storyId, $storyData, publish: $publish);

        if (!$updateResponse->isOk()) {
            throw new \RuntimeException(
                'Failed to update story: ' . $updateResponse->getErrorMessage(),
            );
        }

        return new StoryUpdateResult(
            story: $updateResponse->data(),
            appliedContent: $resolvedContent,
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

    /**
     * @throws \RuntimeException if the story is not found
     */
    private function resolveStoryId(
        StoryApi $storyApi,
        ?string $storySlug,
        ?string $storyId,
    ): string {
        if ($storySlug !== null && $storyId === null) {
            $params = new StoriesParams(withSlug: $storySlug);
            $stories = $storyApi->page($params)->data();

            if (count($stories) !== 1) {
                throw new \RuntimeException(
                    'Story not found with slug: ' . $storySlug,
                );
            }

            /** @var array{id: int|string} $storyItem */
            $storyItem = $stories[0];

            return (string) $storyItem['id'];
        }

        if ($storyId === null) {
            throw new \RuntimeException('Provide either a story slug or ID.');
        }

        return $storyId;
    }
}
