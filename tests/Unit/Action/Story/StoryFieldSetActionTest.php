<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Story;

use Blokctl\Action\Story\StoryFieldSetAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class StoryFieldSetActionTest extends TestCase
{
    #[Test]
    public function execute_sets_field_by_story_id(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-story'),  // StoryApi->get
            $this->mockResponse('one-story'),  // StoryApi->update
        );

        $action = new StoryFieldSetAction($client);
        $result = $action->execute(
            '680',
            fieldName: 'headline',
            fieldValue: 'My new headline',
            storyId: '440448565',
        );

        $this->assertSame('My third post', $result->story->name());
        $this->assertSame('headline', $result->fieldName);
        $this->assertSame('My new headline', $result->newValue);
    }

    #[Test]
    public function execute_sets_field_by_story_slug(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-stories-single'), // StoryApi->page (slug lookup)
            $this->mockResponse('one-story'),           // StoryApi->get
            $this->mockResponse('one-story'),           // StoryApi->update
        );

        $action = new StoryFieldSetAction($client);
        $result = $action->execute(
            '680',
            fieldName: 'title',
            fieldValue: 'Updated title',
            storySlug: 'articles/my-article',
        );

        $this->assertSame('title', $result->fieldName);
        $this->assertSame('Updated title', $result->newValue);
    }

    #[Test]
    public function execute_tracks_previous_value(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-story'), // StoryApi->get
            $this->mockResponse('one-story'), // StoryApi->update
        );

        $action = new StoryFieldSetAction($client);
        $result = $action->execute(
            '680',
            fieldName: 'headline',
            fieldValue: 'New headline',
            storyId: '440448565',
        );

        $this->assertSame('headline', $result->fieldName);
        $this->assertSame('New headline', $result->newValue);
        // The one-story fixture has no "headline" field in content, so previous is null
        $this->assertNull($result->previousValue);
    }

    #[Test]
    public function execute_throws_when_story_not_found_by_slug(): void
    {
        $emptyJson = json_encode(['stories' => []], JSON_THROW_ON_ERROR);

        $client = $this->createMockClient(
            new \Symfony\Component\HttpClient\Response\MockResponse(
                $emptyJson,
                ['http_code' => 200],
            ),
        );

        $action = new StoryFieldSetAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Story not found with slug: nonexistent');

        $action->execute('680', 'headline', 'value', storySlug: 'nonexistent');
    }

    #[Test]
    public function execute_throws_when_no_story_identifier(): void
    {
        $client = $this->createMockClient();

        $action = new StoryFieldSetAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Provide either a story slug or ID');

        $action->execute('680', 'headline', 'value');
    }

    #[Test]
    public function upload_asset_throws_when_local_file_not_found(): void
    {
        $client = $this->createMockClient();

        $action = new StoryFieldSetAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File not found: /nonexistent/image.jpg');

        $action->uploadAsset('680', '/nonexistent/image.jpg');
    }
}
