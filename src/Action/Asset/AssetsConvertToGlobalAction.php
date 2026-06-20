<?php

declare(strict_types=1);

namespace Blokctl\Action\Asset;

use Storyblok\ManagementApi\Data\Asset;
use Storyblok\ManagementApi\Data\AssetFolder;
use Storyblok\ManagementApi\Endpoints\AssetApi;
use Storyblok\ManagementApi\Endpoints\AssetFolderApi;
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\QueryParameters\AssetsParams;
use Storyblok\ManagementApi\QueryParameters\PaginationParams;

final readonly class AssetsConvertToGlobalAction
{
    private const int PER_PAGE = 1000;

    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * @param list<int> $assetIds
     * @param list<string> $extensions
     * @param list<string> $tags
     */
    public function execute(
        string $spaceId,
        int $targetSharedFolderId,
        array $assetIds = [],
        ?int $sourceFolderId = null,
        ?string $sourceFolderName = null,
        ?string $filetype = null,
        array $extensions = [],
        array $tags = [],
        bool $dryRun = false,
        bool $continueOnError = false,
    ): AssetsConvertToGlobalResult {
        $this->validateSelector($assetIds, $sourceFolderId, $sourceFolderName, $filetype, $extensions, $tags);

        $resolvedAssetIds = $assetIds;
        if ($sourceFolderId !== null || $sourceFolderName !== null) {
            $folderId = $sourceFolderId ?? $this->resolveFolderIdByName($spaceId, (string) $sourceFolderName);
            $resolvedAssetIds = $this->resolveAssetIdsByFolder(
                $spaceId,
                $folderId,
                $filetype,
                $extensions,
                $tags,
            );
        }

        $resolvedAssetIds = $this->uniquePositiveIntegers($resolvedAssetIds);
        if ($resolvedAssetIds === []) {
            return new AssetsConvertToGlobalResult([], [], dryRun: $dryRun);
        }

        if ($dryRun) {
            return new AssetsConvertToGlobalResult($resolvedAssetIds, [], dryRun: true);
        }

        $assetApi = new AssetApi($this->client, $spaceId);
        $converted = [];
        $errors = [];
        foreach ($resolvedAssetIds as $assetId) {
            try {
                $response = $assetApi->convert($assetId, $targetSharedFolderId);
                if (!$response->isOk()) {
                    throw new \RuntimeException($response->getErrorMessage());
                }

                $converted[] = $assetId;
            } catch (\Throwable $throwable) {
                $errors[] = sprintf('Asset %d: %s', $assetId, $throwable->getMessage());
                if (!$continueOnError) {
                    throw new \RuntimeException('Failed to convert asset ' . $assetId . ': ' . $throwable->getMessage(), $throwable->getCode(), previous: $throwable);
                }
            }
        }

        return new AssetsConvertToGlobalResult($resolvedAssetIds, $converted, $errors);
    }

    /**
     * @param list<int> $assetIds
     */
    /**
     * @param list<int> $assetIds
     * @param list<string> $extensions
     * @param list<string> $tags
     */
    private function validateSelector(
        array $assetIds,
        ?int $sourceFolderId,
        ?string $sourceFolderName,
        ?string $filetype,
        array $extensions,
        array $tags,
    ): void {
        $selectorCount = 0;
        if ($assetIds !== []) {
            ++$selectorCount;
        }

        if ($sourceFolderId !== null) {
            ++$selectorCount;
        }

        if ($sourceFolderName !== null && trim($sourceFolderName) !== '') {
            ++$selectorCount;
        }

        if ($selectorCount === 0) {
            throw new \InvalidArgumentException('Provide one source selector: --asset-id/--asset-ids, --source-folder-id, or --source-folder-name.');
        }

        if ($selectorCount > 1) {
            throw new \InvalidArgumentException('Asset selectors and folder selectors are mutually exclusive.');
        }

        if ($assetIds !== [] && ($filetype !== null || $extensions !== [] || $tags !== [])) {
            throw new \InvalidArgumentException('Filters can only be used with folder-based asset selection.');
        }
    }

    private function resolveFolderIdByName(string $spaceId, string $folderName): int
    {
        $matches = [];
        foreach (new AssetFolderApi($this->client, $spaceId)->page()->data() as $folder) {
            if (!$folder instanceof AssetFolder) {
                continue;
            }

            if ($folder->name() === $folderName) {
                $matches[] = (int) $folder->id();
            }
        }

        if ($matches === []) {
            throw new \RuntimeException('No asset folder found with name: ' . $folderName);
        }

        if (count($matches) > 1) {
            throw new \RuntimeException('Multiple asset folders found with name: ' . $folderName . '. Use --source-folder-id instead.');
        }

        return $matches[0];
    }

    /**
     * @param list<string> $extensions
     * @param list<string> $tags
     * @return list<int>
     */
    private function resolveAssetIdsByFolder(
        string $spaceId,
        int $folderId,
        ?string $filetype,
        array $extensions,
        array $tags,
    ): array {
        $assetApi = new AssetApi($this->client, $spaceId);
        $assetIds = [];
        $page = 1;

        do {
            $assets = $assetApi->page(
                new AssetsParams(
                    inFolder: $folderId,
                    withTags: $tags === [] ? null : $tags,
                ),
                new PaginationParams(page: $page, perPage: self::PER_PAGE),
            )->data();

            $count = $assets->count();
            foreach ($assets as $asset) {
                if (!$asset instanceof Asset) {
                    continue;
                }

                if (!$this->matchesFiletype($asset, $filetype)) {
                    continue;
                }

                if (!$this->matchesExtensions($asset, $extensions)) {
                    continue;
                }

                $assetIds[] = (int) $asset->id();
            }

            ++$page;
        } while ($count === self::PER_PAGE);

        return $assetIds;
    }

    private function matchesFiletype(Asset $asset, ?string $filetype): bool
    {
        if ($filetype === null || trim($filetype) === '') {
            return true;
        }

        $contentType = strtolower($asset->contentType());
        return str_starts_with($contentType, strtolower($filetype) . '/');
    }

    /**
     * @param list<string> $extensions
     */
    private function matchesExtensions(Asset $asset, array $extensions): bool
    {
        if ($extensions === []) {
            return true;
        }

        $path = parse_url($asset->filename(), PHP_URL_PATH);
        $extension = strtolower(pathinfo(is_string($path) ? $path : $asset->filename(), PATHINFO_EXTENSION));

        return in_array($extension, array_map(strtolower(...), $extensions), true);
    }

    /**
     * @param list<int> $values
     * @return list<int>
     */
    private function uniquePositiveIntegers(array $values): array
    {
        $unique = [];
        foreach ($values as $value) {
            if ($value < 1) {
                throw new \InvalidArgumentException('Asset IDs must be positive integers.');
            }

            $unique[$value] = $value;
        }

        return array_values($unique);
    }
}
