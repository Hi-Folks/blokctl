<?php

declare(strict_types=1);

namespace Blokctl\Action\Asset;

use Storyblok\Api\Domain\Value\Dto\Pagination;
use Storyblok\Api\Domain\Value\Dto\Version;
use Storyblok\Api\Request\StoriesRequest;
use Storyblok\Api\StoriesApi;
use Storyblok\Api\StoryblokClient;
use Storyblok\ManagementApi\Data\Asset;
use Storyblok\ManagementApi\Endpoints\AssetApi;
use Storyblok\ManagementApi\Endpoints\SpaceApi;
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\QueryParameters\PaginationParams;
use Storyblok\ManagementApi\StoryblokUtils;

final readonly class AssetsUnreferencedAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    public function execute(
        string $spaceId,
        string $region = 'EU',
        int $assetsPerPage = 1000,
        int $storiesPerPage = 100,
    ): AssetsUnreferencedResult {
        // Step 1: Fetch all asset IDs via Management API
        $assetApi = new AssetApi($this->client, $spaceId);
        /** @var array<int, Asset> $allAssets */
        $allAssets = [];
        /** @var array<string, Asset> $assetIdMap */
        $assetIdMap = [];
        $page = 1;

        do {
            $response = $assetApi->page(
                page: new PaginationParams(page: $page, perPage: $assetsPerPage),
            );
            $assets = $response->data();
            $fetchedCount = $assets->count();

            /** @var Asset $asset */
            foreach ($assets as $asset) {
                $allAssets[] = $asset;
                $assetIdMap[$asset->id()] = $asset;
            }

            ++$page;
        } while ($fetchedCount >= $assetsPerPage);

        if (count($allAssets) === 0) {
            return new AssetsUnreferencedResult(
                unreferencedAssets: [],
                totalAssets: 0,
                referencedCount: 0,
                storiesAnalyzed: 0,
            );
        }

        // Step 2: Get preview token and set up CDN client
        $space = (new SpaceApi($this->client))->get($spaceId)->data();
        $token = $space->firstToken();

        if ($token === '') {
            throw new \RuntimeException('No preview access token found for this space.');
        }

        $baseUri = StoryblokUtils::baseUriFromRegionForOauth($region);
        $cdnClient = new StoryblokClient(
            baseUri: $baseUri,
            token: $token,
            timeout: 30,
        );
        $storiesApi = new StoriesApi($cdnClient, 'draft');

        // Step 3: Fetch all stories via CDN and collect referenced asset IDs
        /** @var array<string, true> $referencedIds */
        $referencedIds = [];
        $storiesAnalyzed = 0;
        $page = 1;

        do {
            $request = new StoriesRequest(
                pagination: new Pagination(page: $page, perPage: $storiesPerPage),
                version: Version::Draft,
            );

            $response = $storiesApi->all($request);

            /** @var array<string, mixed> $story */
            foreach ($response->stories as $story) {
                ++$storiesAnalyzed;
                /** @var array<string, mixed> $content */
                $content = $story['content'] ?? [];
                $ids = $this->extractAssetIds($content);
                foreach ($ids as $id) {
                    $referencedIds[$id] = true;
                }
            }

            $fetchedCount = count($response->stories);
            ++$page;
        } while ($fetchedCount >= $storiesPerPage);

        // Step 4: Diff
        $unreferenced = [];
        foreach ($allAssets as $asset) {
            if (!isset($referencedIds[$asset->id()])) {
                $unreferenced[] = $asset;
            }
        }

        return new AssetsUnreferencedResult(
            unreferencedAssets: $unreferenced,
            totalAssets: count($allAssets),
            referencedCount: count($allAssets) - count($unreferenced),
            storiesAnalyzed: $storiesAnalyzed,
        );
    }

    /**
     * Recursively extract asset IDs from story content.
     *
     * Asset fields have the structure: {"id": 12345, "fieldtype": "asset", "filename": "..."}
     * Also handles multi-asset fields where the value is an array of asset objects.
     *
     * @param array<mixed> $data
     * @return array<string> Asset IDs found
     */
    private function extractAssetIds(array $data): array
    {
        /** @var array<string> $ids */
        $ids = [];

        // Check if this node is an asset field
        if (
            isset($data['fieldtype'])
            && $data['fieldtype'] === 'asset'
            && isset($data['id'])
            && is_scalar($data['id'])
            && $data['id'] !== ''
        ) {
            $ids[] = strval($data['id']);
        }

        // Recurse into nested arrays
        foreach ($data as $value) {
            if (is_array($value)) {
                foreach ($this->extractAssetIds($value) as $id) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }
}
