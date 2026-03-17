<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Story;

use Blokctl\Action\Story\ContentResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ContentResolverTest extends TestCase
{
    #[Test]
    public function resolve_passes_through_plain_values(): void
    {
        $resolver = new ContentResolver($this->createMockClient(), '680');

        $content = [
            'headline' => 'Hello World',
            'count' => 42,
            'featured' => true,
        ];

        $result = $resolver->resolve($content);

        $this->assertSame('Hello World', $result['headline']);
        $this->assertSame(42, $result['count']);
        $this->assertTrue($result['featured']);
    }

    #[Test]
    public function resolve_asset_marker_with_url_attempts_download(): void
    {
        $resolver = new ContentResolver($this->createMockClient(), '680');

        // A non-downloadable URL should throw
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to download:');

        $resolver->resolve([
            'cover_image' => ['_asset' => 'http://0.0.0.0/nonexistent.jpg'],
        ]);
    }

    #[Test]
    public function resolve_processes_bloks_with_component_key(): void
    {
        $resolver = new ContentResolver($this->createMockClient(), '680');

        $content = [
            'body' => [
                [
                    'component' => 'text_block',
                    'content' => 'Hello world',
                ],
                [
                    'component' => 'hero_section',
                    'title' => 'Welcome',
                ],
            ],
        ];

        $result = $resolver->resolve($content);

        /** @var array<int, array<string, mixed>> $body */
        $body = $result['body'];
        $this->assertCount(2, $body);

        // First blok
        $this->assertSame('text_block', $body[0]['component']);
        $this->assertSame('Hello world', $body[0]['content']);
        $this->assertArrayHasKey('_uid', $body[0]);
        /** @var string $uid */
        $uid = $body[0]['_uid'];
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uid,
        );

        // Second blok
        $this->assertSame('hero_section', $body[1]['component']);
        $this->assertSame('Welcome', $body[1]['title']);
        $this->assertArrayHasKey('_uid', $body[1]);
    }

    #[Test]
    public function resolve_preserves_existing_uid_in_bloks(): void
    {
        $resolver = new ContentResolver($this->createMockClient(), '680');

        $content = [
            'body' => [
                [
                    '_uid' => 'existing-uid-123',
                    'component' => 'text_block',
                    'content' => 'Hello',
                ],
            ],
        ];

        $result = $resolver->resolve($content);

        /** @var array<int, array<string, mixed>> $body */
        $body = $result['body'];
        $this->assertSame('existing-uid-123', $body[0]['_uid']);
    }

    #[Test]
    public function resolve_handles_nested_asset_in_blok_attempts_download(): void
    {
        $resolver = new ContentResolver($this->createMockClient(), '680');

        // Nested asset URL in a blok should also attempt download
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to download:');

        $resolver->resolve([
            'body' => [
                [
                    'component' => 'hero_section',
                    'title' => 'Welcome',
                    'background' => ['_asset' => 'http://0.0.0.0/bg.jpg'],
                ],
            ],
        ]);
    }

    #[Test]
    public function resolve_handles_nested_bloks_in_blok(): void
    {
        $resolver = new ContentResolver($this->createMockClient(), '680');

        $content = [
            'body' => [
                [
                    'component' => 'section',
                    'columns' => [
                        [
                            'component' => 'column',
                            'text' => 'Left',
                        ],
                        [
                            'component' => 'column',
                            'text' => 'Right',
                        ],
                    ],
                ],
            ],
        ];

        $result = $resolver->resolve($content);

        /** @var array<int, array<string, mixed>> $body */
        $body = $result['body'];
        $this->assertSame('section', $body[0]['component']);
        /** @var array<int, array<string, mixed>> $columns */
        $columns = $body[0]['columns'];
        $this->assertCount(2, $columns);
        $this->assertSame('column', $columns[0]['component']);
        $this->assertSame('Left', $columns[0]['text']);
        $this->assertArrayHasKey('_uid', $columns[0]);
        $this->assertSame('column', $columns[1]['component']);
        $this->assertSame('Right', $columns[1]['text']);
    }

    #[Test]
    public function resolve_asset_marker_throws_when_file_not_found(): void
    {
        $resolver = new ContentResolver($this->createMockClient(), '680');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File not found: /nonexistent/image.jpg');

        $resolver->resolve([
            'image' => ['_asset' => '/nonexistent/image.jpg'],
        ]);
    }

    #[Test]
    public function resolve_converts_link_marker_with_slug(): void
    {
        $resolver = new ContentResolver(
            $this->createMockClient(
                $this->mockResponse('list-stories-single'), // StoryApi->page for slug lookup
            ),
            '680',
        );

        $content = [
            'mylink' => ['_slug' => 'home'],
        ];

        $result = $resolver->resolve($content);

        /** @var array<string, mixed> $link */
        $link = $result['mylink'];
        $this->assertSame('story', $link['linktype']);
        $this->assertSame('multilink', $link['fieldtype']);
        $this->assertSame('home', $link['cached_url']);
        $this->assertSame('e656e146-f4ed-44a2-8017-013e5a9d9395', $link['id']);
    }

    #[Test]
    public function resolve_handles_link_marker_inside_blok(): void
    {
        $resolver = new ContentResolver(
            $this->createMockClient(
                $this->mockResponse('list-stories-single'), // StoryApi->page for slug lookup
            ),
            '680',
        );

        $content = [
            'body' => [
                [
                    'component' => 'cta_block',
                    'label' => 'Click here',
                    'link' => ['_slug' => 'articles/my-article'],
                ],
            ],
        ];

        $result = $resolver->resolve($content);

        /** @var array<int, array<string, mixed>> $body */
        $body = $result['body'];
        /** @var array<string, mixed> $link */
        $link = $body[0]['link'];
        $this->assertSame('story', $link['linktype']);
        $this->assertSame('multilink', $link['fieldtype']);
        $this->assertSame('articles/my-article', $link['cached_url']);
        $this->assertSame('e656e146-f4ed-44a2-8017-013e5a9d9395', $link['id']);
    }

    #[Test]
    public function resolve_link_throws_when_story_not_found(): void
    {
        $emptyJson = json_encode(['stories' => []], JSON_THROW_ON_ERROR);

        $resolver = new ContentResolver(
            $this->createMockClient(
                new \Symfony\Component\HttpClient\Response\MockResponse(
                    $emptyJson,
                    ['http_code' => 200],
                ),
            ),
            '680',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Story not found for link with slug: nonexistent');

        $resolver->resolve([
            'link' => ['_slug' => 'nonexistent'],
        ]);
    }

    #[Test]
    public function resolve_passes_through_root_level_component(): void
    {
        $resolver = new ContentResolver($this->createMockClient(), '680');

        $content = [
            'component' => 'default-page',
            'headline' => 'Hello',
        ];

        $result = $resolver->resolve($content);

        $this->assertSame('default-page', $result['component']);
        $this->assertSame('Hello', $result['headline']);
    }

    #[Test]
    public function resolve_ignores_non_blok_arrays(): void
    {
        $resolver = new ContentResolver($this->createMockClient(), '680');

        $content = [
            'tags' => ['news', 'featured'],
            'ids' => [1, 2, 3],
        ];

        $result = $resolver->resolve($content);

        $this->assertSame(['news', 'featured'], $result['tags']);
        $this->assertSame([1, 2, 3], $result['ids']);
    }
}
