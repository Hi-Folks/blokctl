<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Story;

use Blokctl\Action\Story\StoryTranslatedSlugEnsureAction;
use Blokctl\Action\Story\StoryTranslatedSlugInput;
use Blokctl\Action\Story\StoryTranslatedSlugsEnsureAction;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\TestCase;

final class StoryTranslatedSlugEnsureActionTest extends TestCase
{
    #[Test]
    public function creates_translated_slug_when_missing(): void
    {
        $updateResponse = new MockResponse($this->storyJson());
        $action = new StoryTranslatedSlugEnsureAction($this->createMockClient(
            new MockResponse($this->appProvisionsJson(['translatable-slugs'])),
            new MockResponse($this->spaceJson(['it'])),
            new MockResponse($this->storiesJson([['id' => 440448565, 'slug' => 'about']])),
            new MockResponse($this->storyJson()),
            $updateResponse,
        ));

        $result = $action->execute(
            spaceId: '680',
            storySlug: '/about',
            storyId: null,
            lang: 'it',
            translatedSlug: '/chi-siamo',
            name: 'Chi siamo',
        );

        $this->assertTrue($result->changed);
        $this->assertSame('440448565', $result->storyId);
        $this->assertSame('chi-siamo', $result->translatedSlug);
        $payload = $this->requestJsonPayload($updateResponse);
        $this->assertSame('it', $this->valueAtPath($payload, ['story', 'translated_slugs_attributes', 0, 'lang']));
        $this->assertSame('chi-siamo', $this->valueAtPath($payload, ['story', 'translated_slugs_attributes', 0, 'slug']));
        $this->assertSame('Chi siamo', $this->valueAtPath($payload, ['story', 'translated_slugs_attributes', 0, 'name']));
    }

    #[Test]
    public function skips_when_translated_slug_already_matches(): void
    {
        $action = new StoryTranslatedSlugEnsureAction($this->createMockClient(
            new MockResponse($this->appProvisionsJson(['translatable-slugs'])),
            new MockResponse($this->spaceJson(['it'])),
            new MockResponse($this->storyJson(translatedSlugs: [
                [
                    'id' => 3001,
                    'lang' => 'it',
                    'slug' => 'chi-siamo',
                    'name' => 'Chi siamo',
                ],
            ])),
        ));

        $result = $action->execute(
            spaceId: '680',
            storySlug: null,
            storyId: '440448565',
            lang: 'it',
            translatedSlug: 'chi-siamo',
            name: 'Chi siamo',
        );

        $this->assertFalse($result->changed);
        $this->assertSame('chi-siamo', $result->translatedSlug);
    }

    #[Test]
    public function batches_multiple_translated_slugs_for_one_story_update(): void
    {
        $updateResponse = new MockResponse($this->storyJson());
        $action = new StoryTranslatedSlugsEnsureAction($this->createMockClient(
            new MockResponse($this->appProvisionsJson(['translatable-slugs'])),
            new MockResponse($this->spaceJson(['it', 'de'])),
            new MockResponse($this->storiesJson([['id' => 440448565, 'slug' => 'about']])),
            new MockResponse($this->storyJson()),
            $updateResponse,
        ));

        $result = $action->execute(
            spaceId: '680',
            storySlug: '/about',
            storyId: null,
            translations: [
                new StoryTranslatedSlugInput('it', '/chi-siamo', 'Chi siamo'),
                new StoryTranslatedSlugInput('de', '/uber-uns', 'Uber uns'),
            ],
        );

        $this->assertTrue($result->changed);
        $this->assertSame(2, $result->changedCount);
        $payload = $this->requestJsonPayload($updateResponse);
        $this->assertSame('it', $this->valueAtPath($payload, ['story', 'translated_slugs_attributes', 0, 'lang']));
        $this->assertSame('chi-siamo', $this->valueAtPath($payload, ['story', 'translated_slugs_attributes', 0, 'slug']));
        $this->assertSame('de', $this->valueAtPath($payload, ['story', 'translated_slugs_attributes', 1, 'lang']));
        $this->assertSame('uber-uns', $this->valueAtPath($payload, ['story', 'translated_slugs_attributes', 1, 'slug']));
    }

    #[Test]
    public function accepts_language_known_from_setup_context_even_when_space_read_is_stale(): void
    {
        $updateResponse = new MockResponse($this->storyJson());
        $action = new StoryTranslatedSlugsEnsureAction($this->createMockClient(
            new MockResponse($this->appProvisionsJson(['translatable-slugs'])),
            new MockResponse($this->spaceJson(['de'])),
            new MockResponse($this->storiesJson([['id' => 440448565, 'slug' => 'about']])),
            new MockResponse($this->storyJson()),
            $updateResponse,
        ));

        $result = $action->execute(
            spaceId: '680',
            storySlug: '/about',
            storyId: null,
            translations: [
                new StoryTranslatedSlugInput('it', '/chi-siamo', 'Chi siamo'),
            ],
            knownEnabledLanguages: ['it'],
        );

        $this->assertTrue($result->changed);
        $payload = $this->requestJsonPayload($updateResponse);
        $this->assertSame('it', $this->valueAtPath($payload, ['story', 'translated_slugs_attributes', 0, 'lang']));
    }

    /**
     * @param string[] $slugs
     */
    private function appProvisionsJson(array $slugs): string
    {
        return json_encode([
            'app_provisions' => array_map(static fn(string $slug): array => [
                'slug' => $slug,
                'app_id' => crc32($slug),
                'name' => $slug,
            ], $slugs),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param string[] $languages
     */
    private function spaceJson(array $languages): string
    {
        return json_encode([
            'space' => [
                'id' => 680,
                'name' => 'Example Space',
                'languages' => array_map(static fn(string $code): array => [
                    'code' => $code,
                ], $languages),
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<array{id: int, slug: string}> $stories
     */
    private function storiesJson(array $stories): string
    {
        return json_encode([
            'stories' => array_map(static fn(array $story): array => [
                'name' => ucfirst($story['slug']),
                'id' => $story['id'],
                'slug' => $story['slug'],
                'full_slug' => $story['slug'],
                'content' => [
                    'component' => 'default-page',
                ],
            ], $stories),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<array<string, mixed>> $translatedSlugs
     */
    private function storyJson(array $translatedSlugs = []): string
    {
        return json_encode([
            'story' => [
                'name' => 'About',
                'id' => 440448565,
                'uuid' => 'e656e146-f4ed-44a2-8017-013e5a9d9395',
                'slug' => 'about',
                'full_slug' => 'about',
                'content' => [
                    '_uid' => 'root',
                    'component' => 'default-page',
                ],
                'parent_id' => 0,
                'translated_slugs' => $translatedSlugs,
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<mixed>
     */
    private function requestJsonPayload(MockResponse $response): array
    {
        $body = $response->getRequestOptions()['body'];
        $this->assertIsString($body);
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * @param array<mixed> $payload
     * @param list<int|string> $path
     */
    private function valueAtPath(array $payload, array $path): mixed
    {
        $current = $payload;
        foreach ($path as $segment) {
            $this->assertIsArray($current);
            $this->assertArrayHasKey($segment, $current);
            $current = $current[$segment];
        }

        return $current;
    }
}
