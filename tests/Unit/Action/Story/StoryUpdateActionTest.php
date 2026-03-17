<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Story;

use Blokctl\Action\Story\StoryUpdateAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class StoryUpdateActionTest extends TestCase
{
    #[Test]
    public function execute_updates_story_content_by_id(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-story'), // StoryApi->get
            $this->mockResponse('one-story'), // StoryApi->update
        );

        $action = new StoryUpdateAction($client);
        $result = $action->execute('680', [
            'headline' => 'Updated headline',
        ], storyId: '440448565');

        $this->assertSame('My third post', $result->story->name());
        $this->assertSame('Updated headline', $result->appliedContent['headline']);
    }

    #[Test]
    public function execute_updates_story_content_by_slug(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-stories-single'), // StoryApi->page (slug lookup)
            $this->mockResponse('one-story'),           // StoryApi->get
            $this->mockResponse('one-story'),           // StoryApi->update
        );

        $action = new StoryUpdateAction($client);
        $result = $action->execute('680', [
            'title' => 'New title',
            'body' => 'New body',
        ], storySlug: 'articles/my-article');

        $this->assertSame('New title', $result->appliedContent['title']);
        $this->assertSame('New body', $result->appliedContent['body']);
    }

    #[Test]
    public function execute_resolves_asset_url_attempts_download(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-story'), // StoryApi->get
        );

        $action = new StoryUpdateAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to download:');

        $action->execute('680', [
            'cover_image' => ['_asset' => 'http://0.0.0.0/photo.jpg'],
        ], storyId: '440448565');
    }

    #[Test]
    public function execute_resolves_bloks_in_content(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-story'), // StoryApi->get
            $this->mockResponse('one-story'), // StoryApi->update
        );

        $action = new StoryUpdateAction($client);
        $result = $action->execute('680', [
            'body' => [
                ['component' => 'text_block', 'content' => 'Hello'],
            ],
        ], storyId: '440448565');

        /** @var array<int, array<string, mixed>> $body */
        $body = $result->appliedContent['body'];
        $this->assertCount(1, $body);
        $this->assertSame('text_block', $body[0]['component']);
        $this->assertArrayHasKey('_uid', $body[0]);
    }

    #[Test]
    public function execute_throws_when_story_not_found(): void
    {
        $emptyJson = json_encode(['stories' => []], JSON_THROW_ON_ERROR);

        $client = $this->createMockClient(
            new \Symfony\Component\HttpClient\Response\MockResponse(
                $emptyJson,
                ['http_code' => 200],
            ),
        );

        $action = new StoryUpdateAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Story not found with slug: nonexistent');

        $action->execute('680', ['headline' => 'Test'], storySlug: 'nonexistent');
    }

    #[Test]
    public function parse_json_returns_array(): void
    {
        $action = new StoryUpdateAction($this->createMockClient());
        $result = $action->parseJson('{"headline": "Hello"}');

        $this->assertSame('Hello', $result['headline']);
    }

    #[Test]
    public function parse_json_throws_on_invalid(): void
    {
        $action = new StoryUpdateAction($this->createMockClient());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON');

        $action->parseJson('{invalid}');
    }

    #[Test]
    public function parse_json_file_throws_when_not_found(): void
    {
        $action = new StoryUpdateAction($this->createMockClient());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Content file not found');

        $action->parseJsonFile('/nonexistent/file.json');
    }
}
