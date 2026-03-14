<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Story;

use Blokctl\Action\Story\StoryWorkflowChangeAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class StoryWorkflowChangeActionTest extends TestCase
{
    #[Test]
    public function resolve_workflow_stage_by_name(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),       // WorkflowApi->list
            $this->mockResponse('list-workflow-stages'),  // WorkflowStageApi->list
        );

        $action = new StoryWorkflowChangeAction($client);
        $resolved = $action->resolveWorkflowStage('680', stageName: 'Drafting');

        $this->assertSame(653554, $resolved['stageId']);
        $this->assertSame('Drafting', $resolved['stageName']);
        $this->assertCount(2, $resolved['workflowStages']);
    }

    #[Test]
    public function resolve_workflow_stage_by_name_case_insensitive(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),
            $this->mockResponse('list-workflow-stages'),
        );

        $action = new StoryWorkflowChangeAction($client);
        $resolved = $action->resolveWorkflowStage('680', stageName: 'review');

        $this->assertSame(653555, $resolved['stageId']);
        $this->assertSame('Review', $resolved['stageName']);
    }

    #[Test]
    public function resolve_workflow_stage_returns_available_stages_when_no_name(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),
            $this->mockResponse('list-workflow-stages'),
        );

        $action = new StoryWorkflowChangeAction($client);
        $resolved = $action->resolveWorkflowStage('680');

        $this->assertSame(0, $resolved['stageId']);
        $this->assertSame('', $resolved['stageName']);
        $this->assertArrayHasKey(653554, $resolved['workflowStages']);
        $this->assertArrayHasKey(653555, $resolved['workflowStages']);
    }

    #[Test]
    public function resolve_workflow_stage_throws_when_name_not_found(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),
            $this->mockResponse('list-workflow-stages'),
        );

        $action = new StoryWorkflowChangeAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Workflow stage not found with name: Nonexistent');

        $action->resolveWorkflowStage('680', stageName: 'Nonexistent');
    }

    #[Test]
    public function resolve_workflow_stage_by_workflow_name(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),
            $this->mockResponse('list-workflow-stages'),
        );

        $action = new StoryWorkflowChangeAction($client);
        $resolved = $action->resolveWorkflowStage('680', stageName: 'Drafting', workflowName: 'Article ');

        $this->assertSame(653554, $resolved['stageId']);
    }

    #[Test]
    public function resolve_workflow_stage_throws_when_workflow_name_not_found(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),
        );

        $action = new StoryWorkflowChangeAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Workflow not found with name: Nonexistent');

        $action->resolveWorkflowStage('680', stageName: 'Drafting', workflowName: 'Nonexistent');
    }

    #[Test]
    public function execute_changes_workflow_stage_by_story_id(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-story-with-stage'),  // StoryApi->get
            $this->mockResponse('one-workflow-stage-change'), // WorkflowStageChangeApi->create
        );

        $action = new StoryWorkflowChangeAction($client);
        $result = $action->execute(
            spaceId: '680',
            workflowStageId: 653555,
            workflowStageName: 'Review',
            storyId: '440448565',
        );

        $this->assertSame('My third post', $result->story->name());
        $this->assertSame('Review', $result->workflowStageName);
        $this->assertSame(653555, $result->workflowStageId);
        $this->assertSame(653554, $result->previousWorkflowStageId);
    }

    #[Test]
    public function execute_changes_workflow_stage_by_story_slug(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-stories-single'),      // StoryApi->page (slug lookup)
            $this->mockResponse('one-story-with-stage'),     // StoryApi->get
            $this->mockResponse('one-workflow-stage-change'), // WorkflowStageChangeApi->create
        );

        $action = new StoryWorkflowChangeAction($client);
        $result = $action->execute(
            spaceId: '680',
            workflowStageId: 653555,
            workflowStageName: 'Review',
            storySlug: 'posts/my-third-post',
        );

        $this->assertSame('My third post', $result->story->name());
        $this->assertSame(653555, $result->workflowStageId);
    }

    #[Test]
    public function execute_handles_story_without_previous_stage(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-story'),                // StoryApi->get (no stage)
            $this->mockResponse('one-workflow-stage-change'), // WorkflowStageChangeApi->create
        );

        $action = new StoryWorkflowChangeAction($client);
        $result = $action->execute(
            spaceId: '680',
            workflowStageId: 653554,
            workflowStageName: 'Drafting',
            storyId: '440448565',
        );

        $this->assertNull($result->previousWorkflowStageId);
        $this->assertSame(653554, $result->workflowStageId);
    }

    #[Test]
    public function execute_throws_when_story_slug_not_found(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('empty-stories'),
        );

        $action = new StoryWorkflowChangeAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Story not found with slug: nonexistent');

        $action->execute(
            spaceId: '680',
            workflowStageId: 653554,
            workflowStageName: 'Drafting',
            storySlug: 'nonexistent',
        );
    }

    #[Test]
    public function execute_throws_when_no_story_id_or_slug(): void
    {
        $action = new StoryWorkflowChangeAction(
            $this->createMockClient(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Provide either a story ID or slug.');

        $action->execute(
            spaceId: '680',
            workflowStageId: 653554,
            workflowStageName: 'Drafting',
        );
    }
}
