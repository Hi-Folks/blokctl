<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Story;

use Blokctl\Action\Story\StoryMoveAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class StoryMoveActionTest extends TestCase
{
    #[Test]
    public function resolve_folder_by_slug(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-folders-single'), // StoryApi->page (folderOnly)
        );

        $action = new StoryMoveAction($client);
        $folderId = $action->resolveFolderBySlug('680', 'archived/authors');

        $this->assertSame(789012, $folderId);
    }

    #[Test]
    public function resolve_folder_by_slug_throws_when_not_found(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('empty-stories'),
        );

        $action = new StoryMoveAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Folder not found with slug: nonexistent');

        $action->resolveFolderBySlug('680', 'nonexistent');
    }

    #[Test]
    public function execute_moves_story_by_id(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-story'),       // StoryApi->get
            $this->mockResponse('one-story-moved'),  // StoryApi->update
        );

        $action = new StoryMoveAction($client);
        $result = $action->execute(
            spaceId: '680',
            folderId: 789012,
            storyId: '440448565',
        );

        $this->assertSame('My third post', $result->story->name());
        $this->assertSame(440448337, $result->previousFolderId);
        $this->assertSame(789012, $result->newFolderId);
        $this->assertSame('posts/my-third-post', $result->previousFullSlug);
    }

    #[Test]
    public function execute_moves_story_by_slug(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-stories-single'), // StoryApi->page (slug lookup)
            $this->mockResponse('one-story'),           // StoryApi->get
            $this->mockResponse('one-story-moved'),     // StoryApi->update
        );

        $action = new StoryMoveAction($client);
        $result = $action->execute(
            spaceId: '680',
            folderId: 789012,
            storySlug: 'posts/my-third-post',
        );

        $this->assertSame('My third post', $result->story->name());
        $this->assertSame(789012, $result->newFolderId);
    }

    #[Test]
    public function execute_throws_when_story_slug_not_found(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('empty-stories'),
        );

        $action = new StoryMoveAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Story not found with slug: nonexistent');

        $action->execute(
            spaceId: '680',
            folderId: 789012,
            storySlug: 'nonexistent',
        );
    }

    #[Test]
    public function execute_throws_when_no_story_id_or_slug(): void
    {
        $action = new StoryMoveAction(
            $this->createMockClient(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Provide either a story ID or slug.');

        $action->execute(
            spaceId: '680',
            folderId: 789012,
        );
    }
}
