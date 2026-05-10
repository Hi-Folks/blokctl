<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Story;

use Blokctl\Action\Story\StoryWorkflowChangeAction;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\TestCase;

final class StoryWorkflowChangeActionTest extends TestCase
{
    #[Test]
    public function preflight_returns_available_workflow_stages(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),
            $this->mockResponse('list-workflow-stages'),
        );

        $action = new StoryWorkflowChangeAction($client);
        $result = $action->preflight('680');

        $this->assertSame('12346', $result->workflowId);
        $this->assertSame([
            653554 => 'Drafting',
            653555 => 'Review',
        ], $result->workflowStages);
    }

    #[Test]
    public function preflight_resolves_workflow_by_name(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),
            $this->mockResponse('list-workflow-stages'),
        );

        $action = new StoryWorkflowChangeAction($client);
        $result = $action->preflight('680', workflowName: 'Article ');

        $this->assertSame('12345', $result->workflowId);
        $this->assertArrayHasKey(653554, $result->workflowStages);
    }

    #[Test]
    public function preflight_throws_when_workflow_name_not_found(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),
        );

        $action = new StoryWorkflowChangeAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Workflow not found with name: Nonexistent');

        $action->preflight('680', workflowName: 'Nonexistent');
    }

    #[Test]
    public function preflight_throws_when_workflow_id_not_found(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),
        );

        $action = new StoryWorkflowChangeAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Workflow not found with ID: 99999');

        $action->preflight('680', workflowId: '99999');
    }

    #[Test]
    public function execute_changes_workflow_stage_by_story_slug_and_stage_name(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),
            $this->mockResponse('list-workflow-stages'),
            $this->mockResponse('list-stories-single'),
            $this->mockResponse('one-story-with-stage'),
            $this->mockResponse('one-workflow-stage-change'),
        );

        $action = new StoryWorkflowChangeAction($client);
        $result = $action->execute(
            spaceId: '680',
            storySlug: 'posts/my-third-post',
            stageName: 'review',
        );

        $this->assertSame('My third post', $result->story->name());
        $this->assertSame('Review', $result->workflowStageName);
        $this->assertSame(653555, $result->workflowStageId);
        $this->assertSame(653554, $result->previousWorkflowStageId);
    }

    #[Test]
    public function execute_changes_workflow_stage_by_story_id_and_stage_id(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),
            $this->mockResponse('list-workflow-stages'),
            $this->mockResponse('one-story-with-stage'),
            $this->mockResponse('one-workflow-stage-change'),
        );

        $action = new StoryWorkflowChangeAction($client);
        $result = $action->execute(
            spaceId: '680',
            storyId: '440448565',
            stageId: 653555,
        );

        $this->assertSame('My third post', $result->story->name());
        $this->assertSame('Review', $result->workflowStageName);
        $this->assertSame(653555, $result->workflowStageId);
        $this->assertSame(653554, $result->previousWorkflowStageId);
    }

    #[Test]
    public function execute_unsets_workflow_stage_by_story_id_with_stage_id_zero(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-story-with-stage'),
            $this->mockResponse('one-workflow-stage-change'),
        );

        $action = new StoryWorkflowChangeAction($client);
        $result = $action->execute(
            spaceId: '680',
            storyId: '440448565',
            stageId: 0,
        );

        $this->assertSame('My third post', $result->story->name());
        $this->assertSame('None', $result->workflowStageName);
        $this->assertSame(0, $result->workflowStageId);
        $this->assertSame(653554, $result->previousWorkflowStageId);
    }

    #[Test]
    public function execute_unsets_workflow_stage_by_story_slug_with_stage_id_zero(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-stories-single'),
            $this->mockResponse('one-story-with-stage'),
            $this->mockResponse('one-workflow-stage-change'),
        );

        $action = new StoryWorkflowChangeAction($client);
        $result = $action->execute(
            spaceId: '680',
            storySlug: 'posts/my-third-post',
            stageId: 0,
        );

        $this->assertSame('My third post', $result->story->name());
        $this->assertSame('None', $result->workflowStageName);
        $this->assertSame(0, $result->workflowStageId);
    }

    #[Test]
    public function execute_changes_workflow_stage_scoped_by_workflow_name(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),
            $this->mockResponse('list-workflow-stages'),
            $this->mockResponse('one-story-with-stage'),
            $this->mockResponse('one-workflow-stage-change'),
        );

        $action = new StoryWorkflowChangeAction($client);
        $result = $action->execute(
            spaceId: '680',
            storyId: '440448565',
            stageName: 'Drafting',
            workflowName: 'Article ',
        );

        $this->assertSame('Drafting', $result->workflowStageName);
        $this->assertSame(653554, $result->workflowStageId);
    }

    #[Test]
    public function execute_changes_workflow_stage_scoped_by_workflow_id(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),
            $this->mockResponse('list-workflow-stages'),
            $this->mockResponse('one-story-with-stage'),
            $this->mockResponse('one-workflow-stage-change'),
        );

        $action = new StoryWorkflowChangeAction($client);
        $result = $action->execute(
            spaceId: '680',
            storyId: '440448565',
            stageName: 'Drafting',
            workflowId: '12345',
        );

        $this->assertSame('Drafting', $result->workflowStageName);
        $this->assertSame(653554, $result->workflowStageId);
    }

    #[Test]
    public function execute_handles_story_without_previous_stage(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),
            $this->mockResponse('list-workflow-stages'),
            $this->mockResponse('one-story'),
            $this->mockResponse('one-workflow-stage-change'),
        );

        $action = new StoryWorkflowChangeAction($client);
        $result = $action->execute(
            spaceId: '680',
            storyId: '440448565',
            stageName: 'Drafting',
        );

        $this->assertNull($result->previousWorkflowStageId);
        $this->assertSame(653554, $result->workflowStageId);
    }

    #[Test]
    public function execute_throws_when_story_slug_not_found(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),
            $this->mockResponse('list-workflow-stages'),
            $this->mockResponse('empty-stories'),
        );

        $action = new StoryWorkflowChangeAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Story not found with slug: nonexistent');

        $action->execute(
            spaceId: '680',
            storySlug: 'nonexistent',
            stageName: 'Drafting',
        );
    }

    #[Test]
    public function execute_throws_when_stage_name_not_found(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),
            $this->mockResponse('list-workflow-stages'),
        );

        $action = new StoryWorkflowChangeAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Workflow stage not found with name: Nonexistent');

        $action->execute(
            spaceId: '680',
            storyId: '440448565',
            stageName: 'Nonexistent',
        );
    }

    #[Test]
    public function execute_throws_when_stage_id_not_found(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),
            $this->mockResponse('list-workflow-stages'),
        );

        $action = new StoryWorkflowChangeAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Workflow stage not found with ID: 999999');

        $action->execute(
            spaceId: '680',
            storyId: '440448565',
            stageId: 999999,
        );
    }

    #[Test]
    public function execute_throws_when_story_slug_matches_multiple_stories(): void
    {
        $storiesJson = json_encode([
            'stories' => [
                [
                    'name' => 'First match',
                    'id' => 440448565,
                    'uuid' => 'e656e146-f4ed-44a2-8017-013e5a9d9395',
                    'slug' => 'my-third-post',
                    'full_slug' => 'posts/my-third-post',
                    'tag_list' => [],
                ],
                [
                    'name' => 'Second match',
                    'id' => 440448566,
                    'uuid' => 'e656e146-f4ed-44a2-8017-013e5a9d9396',
                    'slug' => 'my-third-post',
                    'full_slug' => 'posts/my-third-post',
                    'tag_list' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),
            $this->mockResponse('list-workflow-stages'),
            new MockResponse($storiesJson, ['http_code' => 200]),
        );

        $action = new StoryWorkflowChangeAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Multiple stories found with slug: posts/my-third-post');

        $action->execute(
            spaceId: '680',
            storySlug: 'posts/my-third-post',
            stageName: 'Drafting',
        );
    }

    #[Test]
    public function execute_throws_when_no_story_id_or_slug(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),
            $this->mockResponse('list-workflow-stages'),
        );

        $action = new StoryWorkflowChangeAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Provide either a story ID or slug.');

        $action->execute(
            spaceId: '680',
            stageName: 'Drafting',
        );
    }

    #[Test]
    public function execute_throws_when_no_stage_name_or_id(): void
    {
        $action = new StoryWorkflowChangeAction($this->createMockClient());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Provide either a workflow stage name or ID.');

        $action->execute(
            spaceId: '680',
            storyId: '440448565',
        );
    }

    #[Test]
    public function execute_throws_when_story_slug_and_id_are_provided(): void
    {
        $action = new StoryWorkflowChangeAction($this->createMockClient());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Provide only one of story slug or ID.');

        $action->execute(
            spaceId: '680',
            storyId: '440448565',
            storySlug: 'posts/my-third-post',
            stageName: 'Drafting',
        );
    }

    #[Test]
    public function execute_throws_when_stage_name_and_id_are_provided(): void
    {
        $action = new StoryWorkflowChangeAction($this->createMockClient());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Provide only one of workflow stage name or ID.');

        $action->execute(
            spaceId: '680',
            storyId: '440448565',
            stageName: 'Drafting',
            stageId: 653554,
        );
    }

    #[Test]
    public function execute_throws_when_workflow_name_and_id_are_provided(): void
    {
        $action = new StoryWorkflowChangeAction($this->createMockClient());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Provide only one of workflow name or ID.');

        $action->execute(
            spaceId: '680',
            storyId: '440448565',
            stageName: 'Drafting',
            workflowName: 'Article ',
            workflowId: '12345',
        );
    }
}
