<?php

declare(strict_types=1);

namespace Blokctl\Action\Story;

use Storyblok\ManagementApi\Data\Fields\AssetField;
use Storyblok\ManagementApi\Endpoints\AssetApi;
use Storyblok\ManagementApi\Endpoints\StoryApi;
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\QueryParameters\StoriesParams;

/**
 * Resolves simplified content JSON into Storyblok-ready content.
 *
 * Conventions:
 * - { "_asset": "url-or-path" } → asset field (URL downloaded and uploaded, local file uploaded)
 * - { "_slug": "story-slug" } → multilink field pointing to a story
 * - Arrays of objects with "component" key → bloks (processed recursively, _uid generated)
 * - Everything else passes through as-is
 */
final class ContentResolver
{
    private ?AssetApi $assetApi = null;

    public function __construct(
        private readonly ManagementApiClient $client,
        private readonly string $spaceId,
    ) {}

    /**
     * Resolve a content tree, processing _asset markers and bloks recursively.
     *
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    public function resolve(array $content): array
    {
        $resolved = [];

        foreach ($content as $key => $value) {
            $resolved[$key] = $this->resolveValue($value);
        }

        return $resolved;
    }

    /**
     * Resolve a single value, dispatching based on type.
     */
    private function resolveValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        // Asset marker: { "_asset": "..." }
        if ($this->isAssetMarker($value)) {
            /** @var string $assetSource */
            $assetSource = $value['_asset'];

            return $this->resolveAsset($assetSource);
        }

        // Link marker: { "_slug": "..." }
        if ($this->isLinkMarker($value)) {
            /** @var string $slug */
            $slug = $value['_slug'];

            return $this->resolveLink($slug);
        }

        // Array of bloks: [{ "component": "...", ... }, ...]
        if ($this->isBloksArray($value)) {
            /** @var array<int, mixed> $bloksArray */
            $bloksArray = $value;

            return $this->resolveBloks($bloksArray);
        }

        // Generic array or object — recurse
        /** @var array<string|int, mixed> $result */
        $result = [];
        foreach ($value as $k => $v) {
            $result[$k] = $this->resolveValue($v);
        }

        return $result;
    }

    /**
     * Check if a value is an asset marker: { "_asset": "..." }
     *
     * @param array<string|int, mixed> $value
     */
    private function isAssetMarker(array $value): bool
    {
        return isset($value['_asset']) && is_string($value['_asset']);
    }

    /**
     * Check if a value is a link marker: { "_slug": "..." }
     *
     * @param array<string|int, mixed> $value
     */
    private function isLinkMarker(array $value): bool
    {
        return isset($value['_slug']) && is_string($value['_slug']);
    }

    /**
     * Check if a value is an array of bloks (objects with "component" key).
     *
     * @param array<string|int, mixed> $value
     */
    private function isBloksArray(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        // Must be a sequential array
        if (!array_is_list($value)) {
            return false;
        }

        // At least one item must have a "component" key
        foreach ($value as $item) {
            if (is_array($item) && isset($item['component'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve an asset marker to an AssetField array.
     *
     * - URL: downloads to a temp file, uploads to Storyblok, returns AssetField
     * - Local file: uploads to Storyblok directly
     *
     * @return array<string, mixed>
     */
    private function resolveAsset(string $source): array
    {
        // URL: download to temp file, then upload
        if (str_starts_with($source, 'http://') || str_starts_with($source, 'https://')) {
            $source = $this->downloadToTemp($source);
        }

        // Local file
        if (!file_exists($source)) {
            throw new \RuntimeException('File not found: ' . $source);
        }

        $asset = $this->getAssetApi()->upload($source)->data();
        $assetField = AssetField::makeFromAsset($asset);

        return $assetField->toArray();
    }

    /**
     * Resolve a link marker to a multilink field array.
     *
     * Looks up the story by slug to get its UUID for the "id" field.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException if the story is not found
     */
    private function resolveLink(string $slug): array
    {
        $storyApi = new StoryApi($this->client, $this->spaceId);
        $stories = $storyApi->page(new StoriesParams(withSlug: $slug))->data();

        if (count($stories) !== 1) {
            throw new \RuntimeException('Story not found for link with slug: ' . $slug);
        }

        /** @var array{uuid: string} $story */
        $story = $stories[0];

        return [
            'id' => (string) $story['uuid'],
            'linktype' => 'story',
            'fieldtype' => 'multilink',
            'cached_url' => $slug,
        ];
    }

    /**
     * Resolve an array of bloks, adding _uid and recursing into each blok's fields.
     *
     * @param array<int, mixed> $bloks
     *
     * @return array<int, array<string, mixed>>
     */
    private function resolveBloks(array $bloks): array
    {
        $resolved = [];

        foreach ($bloks as $blok) {
            if (!is_array($blok)) {
                continue;
            }

            if (!isset($blok['component'])) {
                continue;
            }

            // Generate _uid if not provided
            if (!isset($blok['_uid'])) {
                $blok['_uid'] = $this->generateUid();
            }

            // Recurse into the blok's fields
            /** @var array<string, mixed> $resolvedBlok */
            $resolvedBlok = [];
            foreach ($blok as $key => $value) {
                $resolvedBlok[$key] = $this->resolveValue($value);
            }

            $resolved[] = $resolvedBlok;
        }

        return $resolved;
    }

    private function generateUid(): string
    {
        /** @var non-empty-string $data */
        $data = random_bytes(16);
        // Set version to 4
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set variant to RFC 4122
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
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

    private function getAssetApi(): AssetApi
    {
        if (!$this->assetApi instanceof \Storyblok\ManagementApi\Endpoints\AssetApi) {
            $this->assetApi = new AssetApi($this->client, $this->spaceId);
        }

        return $this->assetApi;
    }
}
