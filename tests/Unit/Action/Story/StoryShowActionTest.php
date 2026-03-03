<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Story;

use Blokctl\Action\Story\StoryShowAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class StoryShowActionTest extends TestCase
{
    #[Test]
    public function execute_fetches_story_by_id(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-story'), // StoryApi->get
        );

        $action = new StoryShowAction($client);
        $result = $action->execute('680', id: '440448565');

        $this->assertSame('My third post', $result->story->name());
        $this->assertArrayHasKey('story', $result->fullResponse);
    }

    #[Test]
    public function execute_fetches_story_by_slug(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-stories'), // StoryApi->page (slug search)
            $this->mockResponse('one-story'),    // StoryApi->get (by resolved id)
        );

        $action = new StoryShowAction($client);
        $result = $action->execute('680', slug: 'my-third-post');

        $this->assertSame('My third post', $result->story->name());
    }

    #[Test]
    public function execute_fetches_story_by_uuid(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-stories'), // StoryApi->page (uuid search)
            $this->mockResponse('one-story'),    // StoryApi->get (by resolved id)
        );

        $action = new StoryShowAction($client);
        $result = $action->execute('680', uuid: 'e656e146-f4ed-44a2-8017-013e5a9d9396');

        $this->assertSame('My third post', $result->story->name());
    }

    #[Test]
    public function execute_throws_when_slug_not_found(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('empty-stories'), // StoryApi->page (no results)
        );

        $action = new StoryShowAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Story not found with slug: nonexistent');

        $action->execute('680', slug: 'nonexistent');
    }

    #[Test]
    public function execute_throws_when_uuid_not_found(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('empty-stories'), // StoryApi->page (no results)
        );

        $action = new StoryShowAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Story not found with UUID: bad-uuid');

        $action->execute('680', uuid: 'bad-uuid');
    }
}
