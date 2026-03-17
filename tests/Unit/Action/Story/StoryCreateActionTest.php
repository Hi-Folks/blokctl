<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Story;

use Blokctl\Action\Story\StoryCreateAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class StoryCreateActionTest extends TestCase
{
    #[Test]
    public function execute_creates_story_with_content(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-story'), // StoryApi->create
        );

        $action = new StoryCreateAction($client);
        $result = $action->execute('680', 'My Article', [
            'component' => 'page',
            'title' => 'Hello World',
        ]);

        $this->assertSame('My third post', $result->story->name());
    }

    #[Test]
    public function execute_creates_story_with_custom_slug(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-story'),
        );

        $action = new StoryCreateAction($client);
        $result = $action->execute('680', 'My Article', [
            'component' => 'page',
        ], slug: 'custom-slug');

        $this->assertSame('My third post', $result->story->name());
    }

    #[Test]
    public function execute_creates_story_in_folder(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-story'),
        );

        $action = new StoryCreateAction($client);
        $result = $action->execute('680', 'My Article', [
            'component' => 'page',
            'body' => 'Some text',
        ], parentId: 12345);

        $this->assertSame('My third post', $result->story->name());
    }

    #[Test]
    public function execute_throws_when_component_key_missing(): void
    {
        $client = $this->createMockClient();

        $action = new StoryCreateAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Content must include a "component" key');

        $action->execute('680', 'My Article', [
            'title' => 'No component key',
        ]);
    }

    #[Test]
    public function parse_json_returns_array(): void
    {
        $client = $this->createMockClient();

        $action = new StoryCreateAction($client);
        $result = $action->parseJson('{"component": "page", "title": "Hello"}');

        $this->assertSame('page', $result['component']);
        $this->assertSame('Hello', $result['title']);
    }

    #[Test]
    public function parse_json_throws_on_invalid_json(): void
    {
        $client = $this->createMockClient();

        $action = new StoryCreateAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON');

        $action->parseJson('{invalid}');
    }

    #[Test]
    public function parse_json_file_reads_and_parses(): void
    {
        $client = $this->createMockClient();

        // Use an existing fixture as a JSON file
        $action = new StoryCreateAction($client);
        $result = $action->parseJsonFile(__DIR__ . '/../../../Fixtures/one-story.json');

        $this->assertArrayHasKey('story', $result);
    }

    #[Test]
    public function parse_json_file_throws_when_file_not_found(): void
    {
        $client = $this->createMockClient();

        $action = new StoryCreateAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Content file not found');

        $action->parseJsonFile('/nonexistent/path.json');
    }

    #[Test]
    public function resolve_parent_by_slug_returns_folder_id(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-folders-single'),
        );

        $action = new StoryCreateAction($client);
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

        $action = new StoryCreateAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Parent folder not found with slug: nonexistent');

        $action->resolveParentBySlug('680', 'nonexistent');
    }
}
