<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Workflow;

use Blokctl\Action\Workflow\WorkflowStageShowAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WorkflowStageShowActionTest extends TestCase
{
    #[Test]
    public function execute_finds_stage_by_id_across_all_workflows(): void
    {
        // By ID searches all workflows: first match is in workflow 12345 ("Article ")
        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),
            $this->mockResponse('list-workflow-stages'), // stages for workflow 12345
        );

        $action = new WorkflowStageShowAction($client);
        $result = $action->execute('680', stageId: 653554);

        $this->assertSame(653554, $result->stage['id']);
        $this->assertSame('Drafting', $result->stage['name']);
        $this->assertSame(12345, $result->workflowId);
        $this->assertSame('Article ', $result->workflowName);
    }

    #[Test]
    public function execute_finds_stage_by_name_in_default_workflow(): void
    {
        // By name searches only the default workflow (12346 "Default one")
        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),
            $this->mockResponse('list-workflow-stages'), // stages for default workflow 12346
        );

        $action = new WorkflowStageShowAction($client);
        $result = $action->execute('680', stageName: 'review');

        $this->assertSame(653555, $result->stage['id']);
        $this->assertSame('Review', $result->stage['name']);
        // Searched in the default workflow
        $this->assertSame(12346, $result->workflowId);
        $this->assertSame('Default one', $result->workflowName);
    }

    #[Test]
    public function execute_finds_stage_by_name_in_specified_workflow_by_name(): void
    {
        // --workflow-name scopes to that workflow
        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),
            $this->mockResponse('list-workflow-stages'), // stages for "Article " (12345)
        );

        $action = new WorkflowStageShowAction($client);
        $result = $action->execute('680', stageName: 'drafting', workflowName: 'Article ');

        $this->assertSame(653554, $result->stage['id']);
        $this->assertSame('Drafting', $result->stage['name']);
        $this->assertSame(12345, $result->workflowId);
    }

    #[Test]
    public function execute_scopes_to_workflow_id(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),
            $this->mockResponse('list-workflow-stages'), // stages for workflow 12346
        );

        $action = new WorkflowStageShowAction($client);
        $result = $action->execute('680', stageId: 653554, workflowId: '12346');

        $this->assertSame(653554, $result->stage['id']);
        $this->assertSame(12346, $result->workflowId);
        $this->assertSame('Default one', $result->workflowName);
    }

    #[Test]
    public function execute_throws_when_stage_not_found_by_id(): void
    {
        // By ID searches all workflows (2 in fixture)
        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),
            $this->mockResponse('list-workflow-stages'),
            $this->mockResponse('list-workflow-stages'),
        );

        $action = new WorkflowStageShowAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Workflow stage not found with ID: 999999');

        $action->execute('680', stageId: 999999);
    }

    #[Test]
    public function execute_throws_when_stage_not_found_by_name(): void
    {
        // By name searches only default workflow (1 API call for stages)
        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),
            $this->mockResponse('list-workflow-stages'),
        );

        $action = new WorkflowStageShowAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Workflow stage not found with name: NonExistent');

        $action->execute('680', stageName: 'NonExistent');
    }

    #[Test]
    public function execute_throws_when_workflow_not_found_by_name(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),
        );

        $action = new WorkflowStageShowAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Workflow not found with name: Unknown Workflow');

        $action->execute('680', stageName: 'Drafting', workflowName: 'Unknown Workflow');
    }

    #[Test]
    public function execute_throws_when_workflow_not_found_by_id(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),
        );

        $action = new WorkflowStageShowAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Workflow not found with ID: 99999');

        $action->execute('680', stageId: 653554, workflowId: '99999');
    }

    #[Test]
    public function execute_throws_when_both_workflow_id_and_name_given(): void
    {
        $client = $this->createMockClient();

        $action = new WorkflowStageShowAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Provide only one of workflowId or workflowName');

        $action->execute('680', stageName: 'Drafting', workflowId: '12345', workflowName: 'Article ');
    }
}
