<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Folder;

use Blokctl\Action\Folder\FolderCreateAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FolderCreateActionTest extends TestCase
{
    #[Test]
    public function execute_creates_folder_at_root(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-folder-created'), // StoryApi->create
        );

        $action = new FolderCreateAction($client);
        $result = $action->execute('680', 'Archive');

        $this->assertSame('Archive', $result->folder->name());
        $this->assertSame('archive', $result->folder->slug());
        $this->assertSame(0, $result->parentId);
    }

    #[Test]
    public function execute_creates_folder_with_parent_id(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-folder-created'), // StoryApi->create
        );

        $action = new FolderCreateAction($client);
        $result = $action->execute('680', 'Archive', parentId: 12345);

        $this->assertSame('Archive', $result->folder->name());
        $this->assertSame(12345, $result->parentId);
    }

    #[Test]
    public function resolve_parent_by_slug_returns_folder_id(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-folders-single'), // StoryApi->page (folder query)
        );

        $action = new FolderCreateAction($client);
        $parentId = $action->resolveParentBySlug('680', 'articles');

        $this->assertIsInt($parentId);
        $this->assertGreaterThan(0, $parentId);
    }

    #[Test]
    public function resolve_parent_by_slug_throws_when_not_found(): void
    {
        $emptyJson = json_encode(['stories' => []], JSON_THROW_ON_ERROR);

        $client = $this->createMockClient(
            new \Symfony\Component\HttpClient\Response\MockResponse(
                $emptyJson,
                ['http_code' => 200],
            ),
        );

        $action = new FolderCreateAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Parent folder not found with slug: nonexistent');

        $action->resolveParentBySlug('680', 'nonexistent');
    }

    #[Test]
    public function execute_creates_folder_with_resolved_parent_slug(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-folders-single'),  // resolveParentBySlug
            $this->mockResponse('one-folder-created'),   // StoryApi->create
        );

        $action = new FolderCreateAction($client);
        $parentId = $action->resolveParentBySlug('680', 'articles');
        $result = $action->execute('680', 'Archive', parentId: $parentId);

        $this->assertSame('Archive', $result->folder->name());
        $this->assertGreaterThan(0, $result->parentId);
    }
}
