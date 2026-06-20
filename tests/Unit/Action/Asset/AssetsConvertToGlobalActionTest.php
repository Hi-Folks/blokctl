<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Asset;

use Blokctl\Action\Asset\AssetsConvertToGlobalAction;
use PHPUnit\Framework\Attributes\Test;
use Storyblok\ManagementApi\ManagementApiClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\TestCase;

final class AssetsConvertToGlobalActionTest extends TestCase
{
    #[Test]
    public function converts_explicit_asset_ids_to_target_shared_folder(): void
    {
        $firstConvert = new MockResponse($this->assetJson(101));
        $secondConvert = new MockResponse($this->assetJson(202));
        $client = ManagementApiClient::initTest(new MockHttpClient([
            $firstConvert,
            $secondConvert,
        ]));

        $result = new AssetsConvertToGlobalAction($client)->execute(
            spaceId: '680',
            targetSharedFolderId: 987,
            assetIds: [101, 202, 101],
        );

        $this->assertSame([101, 202], $result->assetIds);
        $this->assertSame([101, 202], $result->convertedAssetIds);
        $this->assertSame(2, $result->converted());
        $this->assertStringContainsString(
            '/v1/spaces/680/assets/101/convert?target_asset_folder_id=987',
            $firstConvert->getRequestUrl(),
        );
        $this->assertSame('POST', $firstConvert->getRequestMethod());
    }

    #[Test]
    public function dry_run_resolves_folder_name_assets_without_converting(): void
    {
        $client = $this->createMockClient(
            new MockResponse($this->assetFoldersJson()),
            new MockResponse($this->assetsJson([
                ['id' => 101, 'filename' => 'https://a.storyblok.com/f/680/logo.png', 'content_type' => 'image/png'],
                ['id' => 202, 'filename' => 'https://a.storyblok.com/f/680/manual.pdf', 'content_type' => 'application/pdf'],
            ])),
        );

        $result = new AssetsConvertToGlobalAction($client)->execute(
            spaceId: '680',
            targetSharedFolderId: 987,
            sourceFolderName: 'Brand',
            filetype: 'image',
            extensions: ['png'],
            dryRun: true,
        );

        $this->assertTrue($result->dryRun);
        $this->assertSame([101], $result->assetIds);
        $this->assertSame([], $result->convertedAssetIds);
    }

    #[Test]
    public function paginates_folder_asset_selection(): void
    {
        $responses = [
            new MockResponse($this->assetsJson($this->manyAssets(1, 1000))),
            new MockResponse($this->assetsJson([
                ['id' => 1001, 'filename' => 'https://a.storyblok.com/f/680/asset-1001.jpg', 'content_type' => 'image/jpeg'],
            ])),
        ];
        $client = ManagementApiClient::initTest(new MockHttpClient($responses));

        $result = new AssetsConvertToGlobalAction($client)->execute(
            spaceId: '680',
            targetSharedFolderId: 987,
            sourceFolderId: 3001,
            filetype: 'image',
            extensions: ['jpg'],
            dryRun: true,
        );

        $this->assertCount(1001, $result->assetIds);
        $this->assertSame(0, $result->converted());
        $this->assertStringContainsString('page=1', $responses[0]->getRequestUrl());
        $this->assertStringContainsString('per_page=1000', $responses[0]->getRequestUrl());
        $this->assertStringContainsString('page=2', $responses[1]->getRequestUrl());
    }

    #[Test]
    public function passes_tag_filters_to_folder_asset_query(): void
    {
        $assetsResponse = new MockResponse($this->assetsJson([]));
        $client = ManagementApiClient::initTest(new MockHttpClient([
            $assetsResponse,
        ]));

        new AssetsConvertToGlobalAction($client)->execute(
            spaceId: '680',
            targetSharedFolderId: 987,
            sourceFolderId: 3001,
            tags: ['brand', 'approved'],
            dryRun: true,
        );

        $this->assertStringContainsString('with_tags=brand%2Capproved', $assetsResponse->getRequestUrl());
    }

    #[Test]
    public function rejects_mixed_asset_and_folder_selectors(): void
    {
        $action = new AssetsConvertToGlobalAction($this->createMockClient());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('mutually exclusive');

        $action->execute(
            spaceId: '680',
            targetSharedFolderId: 987,
            assetIds: [101],
            sourceFolderId: 3001,
        );
    }

    #[Test]
    public function rejects_filters_with_explicit_asset_ids(): void
    {
        $action = new AssetsConvertToGlobalAction($this->createMockClient());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Filters can only be used with folder-based asset selection');

        $action->execute(
            spaceId: '680',
            targetSharedFolderId: 987,
            assetIds: [101],
            filetype: 'image',
        );
    }

    private function assetJson(int $id): string
    {
        return json_encode([
            'id' => $id,
            'filename' => 'https://a.storyblok.com/f/680/asset-' . $id . '.jpg',
            'content_type' => 'image/jpeg',
            'fieldtype' => 'asset',
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<array{id: int, filename: string, content_type: string}> $assets
     */
    private function assetsJson(array $assets): string
    {
        return json_encode(['assets' => $assets], JSON_THROW_ON_ERROR);
    }

    private function assetFoldersJson(): string
    {
        return json_encode([
            'asset_folders' => [
                ['id' => 3001, 'name' => 'Brand', 'parent_id' => null],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<array{id: int, filename: string, content_type: string}>
     */
    private function manyAssets(int $start, int $count): array
    {
        $assets = [];
        for ($id = $start; $id < $start + $count; ++$id) {
            $assets[] = [
                'id' => $id,
                'filename' => 'https://a.storyblok.com/f/680/asset-' . $id . '.jpg',
                'content_type' => 'image/jpeg',
            ];
        }

        return $assets;
    }
}
