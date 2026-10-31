<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Story;

use Blokctl\Action\Story\StoriesPublishAction;
use Blokctl\Action\Story\StoriesPublishResult;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class StoriesPublishActionTest extends TestCase
{
    #[Test]
    public function preflight_plans_publishing_only_for_stories_with_pending_changes(): void
    {
        $list = $this->mockResponse('list-stories-publish-states');
        $result = new StoriesPublishAction($this->createMockClient($list, $this->mockResponse('list-stories-publish-states')))
            ->preflight('680', folders: ['news', 'home/']);

        $this->assertStringContainsString('starts_with=news/&', $list->getRequestUrl());
        $this->assertStringContainsString('story_only=1', $list->getRequestUrl());
        $this->assertSame(['home', 'news/draft'], array_column($result->entriesWithStatus(StoriesPublishResult::PLANNED), 'fullSlug'));
        $this->assertSame(['about'], array_column($result->entriesWithStatus(StoriesPublishResult::SKIPPED), 'fullSlug'));
        $this->assertCount(3, $result->entries, 'Folders are never selected and duplicates are removed.');
    }

    #[Test]
    public function preflight_plans_unpublishing_only_for_published_stories(): void
    {
        $result = new StoriesPublishAction($this->createMockClient(
            $this->mockResponse('list-stories-publish-states'),
        ))->preflight('680', ids: [301, 302, 303], publish: false);

        $this->assertSame(['about', 'home'], array_column($result->entriesWithStatus(StoriesPublishResult::PLANNED), 'fullSlug'));
        $this->assertSame(['news/draft'], array_column($result->entriesWithStatus(StoriesPublishResult::SKIPPED), 'fullSlug'));
    }

    #[Test]
    public function preflight_throws_when_a_slug_does_not_match_a_story(): void
    {
        $action = new StoriesPublishAction($this->createMockClient(
            $this->mockResponse('list-stories-publish-states'),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No story found with slug: missing');

        $action->preflight('680', slugs: ['about', 'missing']);
    }

    #[Test]
    public function preflight_requires_a_selection(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Select stories with at least one slug, ID, or folder.');

        new StoriesPublishAction($this->createMockClient())->preflight('680');
    }

    #[Test]
    public function execute_publishes_planned_stories_and_collects_failures(): void
    {
        $published = $this->mockResponse('one-story-published');
        $action = new StoriesPublishAction($this->createMockClient(
            $published,
            $this->mockResponse('empty-stories', 422),
        ));
        $preflight = new StoriesPublishResult(true, [
            ['id' => 302, 'fullSlug' => 'home', 'status' => StoriesPublishResult::PLANNED, 'message' => ''],
            ['id' => 301, 'fullSlug' => 'about', 'status' => StoriesPublishResult::SKIPPED, 'message' => ''],
            ['id' => 303, 'fullSlug' => 'news/draft', 'status' => StoriesPublishResult::PLANNED, 'message' => ''],
        ]);

        $result = $action->execute('680', $preflight);

        $this->assertStringEndsWith('/v1/spaces/680/stories/302/publish', $published->getRequestUrl());
        $this->assertSame(['home'], array_column($result->entriesWithStatus(StoriesPublishResult::PUBLISHED), 'fullSlug'));
        $this->assertSame(['news/draft'], array_column($result->entriesWithStatus(StoriesPublishResult::FAILED), 'fullSlug'));
        $this->assertTrue($result->hasFailures());
    }

    #[Test]
    public function execute_unpublishes_planned_stories(): void
    {
        $unpublished = $this->mockResponse('one-story-published');
        $result = new StoriesPublishAction($this->createMockClient($unpublished))->execute('680', new StoriesPublishResult(false, [
            ['id' => 301, 'fullSlug' => 'about', 'status' => StoriesPublishResult::PLANNED, 'message' => ''],
        ]));

        $this->assertStringEndsWith('/v1/spaces/680/stories/301/unpublish', $unpublished->getRequestUrl());
        $this->assertSame(['about'], array_column($result->entriesWithStatus(StoriesPublishResult::UNPUBLISHED), 'fullSlug'));
    }
}
