<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

use Storyblok\ManagementApi\Data\Asset;
use Storyblok\ManagementApi\Data\Fields\AssetField;
use Storyblok\ManagementApi\Data\Story;
use Storyblok\ManagementApi\Data\StoryComponent;
use Storyblok\ManagementApi\Endpoints\AssetApi;
use Storyblok\ManagementApi\Endpoints\StoryApi;
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\QueryParameters\StoriesParams;

final readonly class StoryFieldSetAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * Set a content field value on a story.
     *
     * @throws \RuntimeException if the story is not found or the update fails
     */
    public function execute(
        string $spaceId,
        string $fieldName,
        mixed $fieldValue,
        ?string $storySlug = null,
        ?string $storyId = null,
        bool $isAsset = false,
    ): StoryFieldSetResult {
        $storyApi = new StoryApi($this->client, $spaceId);
        $storyId = $this->resolveStoryId($storyApi, $storySlug, $storyId);

        // Fetch the story
        $response = $storyApi->get($storyId);
        if (!$response->isOk()) {
            throw new \RuntimeException('Story not found with ID: ' . $storyId);
        }

        // Get content, update the field
        $storyData = Story::make($response->data()->toArray());
        $content = $storyData->content();

        $previousValue = $content->get($fieldName);

        if ($isAsset) {
            /** @var string $assetSource */
            $assetSource = $fieldValue;
            $fieldValue = $this->applyAsset($content, $fieldName, $spaceId, $assetSource);
        } else {
            $content->set($fieldName, $fieldValue);
        }

        $storyData->setContent($content);

        // Update the story
        $updateResponse = $storyApi->update($storyId, $storyData);

        if (!$updateResponse->isOk()) {
            throw new \RuntimeException(
                'Failed to update story: ' . $updateResponse->getErrorMessage(),
            );
        }

        return new StoryFieldSetResult(
            story: $updateResponse->data(),
            fieldName: $fieldName,
            newValue: $fieldValue,
            previousValue: $previousValue,
        );
    }

    /**
     * Upload a local file to Storyblok.
     *
     * @throws \RuntimeException if the file is not found or the upload fails
     */
    public function uploadAsset(string $spaceId, string $filePath): Asset
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException('File not found: ' . $filePath);
        }

        $assetApi = new AssetApi($this->client, $spaceId);

        return $assetApi->upload($filePath)->data();
    }

    /**
     * Apply an asset to a content field.
     *
     * - Local file: uploads via AssetApi, then uses setAsset() with the Asset object
     * - URL: sets as an AssetField with the URL as the filename
     *
     * @return string The asset URL (for the result DTO)
     *
     * @throws \RuntimeException if the file is not found or the upload fails
     */
    private function applyAsset(
        StoryComponent $content,
        string $fieldName,
        string $spaceId,
        string $source,
    ): string {
        // URL: download to temp file, then upload
        if (str_starts_with($source, 'http://') || str_starts_with($source, 'https://')) {
            $source = $this->downloadToTemp($source);
        }

        // Local file: upload then use setAsset() with the Asset object
        $asset = $this->uploadAsset($spaceId, $source);
        $content->setAsset($fieldName, $asset);

        return $asset->filenameCDN();
    }

    /**
     * Download a URL to a temp file with a proper extension detected from content.
     *
     * @throws \RuntimeException if the download fails
     */
    private function downloadToTemp(string $url): string
    {
        $content = @file_get_contents($url);
        if ($content === false) {
            throw new \RuntimeException('Failed to download: ' . $url);
        }

        $extension = $this->detectExtension($content);
        $tempFile = sys_get_temp_dir() . '/blokctl_' . bin2hex(random_bytes(8)) . '.' . $extension;

        file_put_contents($tempFile, $content);

        return $tempFile;
    }

    /**
     * Detect file extension from content bytes using MIME type detection.
     */
    private function detectExtension(string $content): string
    {
        $mimeToExtension = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/bmp' => 'bmp',
            'image/tiff' => 'tiff',
            'image/avif' => 'avif',
            'image/heic' => 'heic',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'application/pdf' => 'pdf',
        ];

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($content);

        if ($mime !== false && isset($mimeToExtension[$mime])) {
            return $mimeToExtension[$mime];
        }

        return 'bin';
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
