<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Story;

use Blokctl\Action\Story\StoriesTagsAssignAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class StoriesTagsAssignActionTest extends TestCase
{
    #[Test]
    public function execute_tags_story_by_id(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-story'), // StoryApi->get
            $this->mockResponse('one-story'), // StoryApi->update
        );

        $action = new StoriesTagsAssignAction($client);
        $result = $action->execute(
            spaceId: '680',
            storyIds: ['440448565'],
            storySlugs: [],
            tags: ['Landing'],
        );

        $this->assertCount(1, $result->tagged);
        $this->assertSame([], $result->errors);
    }

    #[Test]
    public function execute_resolves_slug_then_tags(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-stories'), // StoryApi->page (slug resolve)
            $this->mockResponse('one-story'),    // StoryApi->get
            $this->mockResponse('one-story'),    // StoryApi->update
        );

        $action = new StoriesTagsAssignAction($client);
        $result = $action->execute(
            spaceId: '680',
            storyIds: [],
            storySlugs: ['my-third-post'],
            tags: ['Article'],
        );

        $this->assertCount(1, $result->tagged);
        $this->assertSame([], $result->errors);
    }

    #[Test]
    public function execute_reports_error_for_unknown_slug(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('empty-stories'), // StoryApi->page (no results)
        );

        $action = new StoriesTagsAssignAction($client);
        $result = $action->execute(
            spaceId: '680',
            storyIds: [],
            storySlugs: ['nonexistent'],
            tags: ['Tag'],
        );

        $this->assertCount(0, $result->tagged);
        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('not found', $result->errors[0]);
    }
}
