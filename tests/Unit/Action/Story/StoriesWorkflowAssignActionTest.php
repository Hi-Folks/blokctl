<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Story;

use Blokctl\Action\Story\StoriesWorkflowAssignAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class StoriesWorkflowAssignActionTest extends TestCase
{
    #[Test]
    public function preflight_counts_stories_without_stage(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-stories-mixed-workflow'), // StoryApi->page
            $this->mockResponse('list-workflows'),             // WorkflowApi->list
            $this->mockResponse('list-workflow-stages'),       // WorkflowStageApi->list
        );

        $action = new StoriesWorkflowAssignAction($client);
        $result = $action->preflight('680');

        $this->assertSame(2, $result->countWithoutStage);
        $this->assertNotEmpty($result->workflowStages);
        $this->assertNotNull($result->defaultStageId);
    }

    #[Test]
    public function preflight_skips_workflow_fetch_when_all_have_stages(): void
    {
        // Create a fixture where all stories have workflow stages
        // The list-stories fixture has stage with workflow_stage_id=null,
        // so we need a single story with a stage
        $storiesJson = json_encode([
            'stories' => [
                [
                    'name' => 'Story With Stage',
                    'id' => 100001,
                    'uuid' => 'aaaa-0001',
                    'slug' => 'story-with-stage',
                    'full_slug' => 'story-with-stage',
                    'tag_list' => [],
                    'stage' => [
                        'workflow_stage_id' => 653554,
                        'workflow_id' => 93606,
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $client = $this->createMockClient(
            new \Symfony\Component\HttpClient\Response\MockResponse(
                $storiesJson,
                ['http_code' => 200],
            ),
        );

        $action = new StoriesWorkflowAssignAction($client);
        $result = $action->preflight('680');

        $this->assertSame(0, $result->countWithoutStage);
        $this->assertNull($result->defaultStageId);
        $this->assertSame([], $result->workflowStages);
    }

    #[Test]
    public function execute_assigns_workflow_stage_to_stories_without_one(): void
    {
        // preflight
        $client = $this->createMockClient(
            $this->mockResponse('list-stories-mixed-workflow'), // StoryApi->page (preflight)
            $this->mockResponse('list-workflows'),             // WorkflowApi->list
            $this->mockResponse('list-workflow-stages'),       // WorkflowStageApi->list
            $this->mockResponse('one-workflow-stage-change'),  // 1st WorkflowStageChangeApi->create
            $this->mockResponse('one-workflow-stage-change'),  // 2nd WorkflowStageChangeApi->create
        );

        $action = new StoriesWorkflowAssignAction($client);
        $preflight = $action->preflight('680');

        $result = $action->execute('680', $preflight, 653554);

        $this->assertCount(2, $result['assigned']);
        $this->assertSame([], $result['errors']);
        $this->assertSame('Story Without Stage', $result['assigned'][0]['name']);
        $this->assertSame('Another Without Stage', $result['assigned'][1]['name']);
    }
}
