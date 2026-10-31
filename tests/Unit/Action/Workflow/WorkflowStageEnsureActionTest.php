<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Workflow;

use Blokctl\Action\Workflow\WorkflowStageEnsureAction;
use Blokctl\Action\Workflow\WorkflowStageEnsureResult;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\TestCase;

final class WorkflowStageEnsureActionTest extends TestCase
{
    #[Test]
    public function execute_creates_stage_with_roles_and_restricts_users(): void
    {
        $create = $this->mockResponse('one-workflow-stage', 201);
        $result = new WorkflowStageEnsureAction($this->createMockClient(
            $this->mockResponse('list-workflows-custom'),
            $this->mockResponse('list-workflow-stages-article'),
            $this->mockResponse('list-space-roles'),
            $create,
        ))->execute('680', 'Article review', 'In review', color: '#ffcc12', position: 2, roles: ['Page Readers']);

        $this->assertSame(WorkflowStageEnsureResult::CREATED, $result->status);
        $this->assertSame(203, $result->stageId());
        $this->assertSame(
            ['workflow_stage' => [
                'workflow_id' => 200,
                'name' => 'In review',
                'color' => '#ffcc12',
                'position' => 2,
                'allow_all_users' => false,
                'space_role_ids' => [236218588949864],
            ]],
            json_decode($this->requestBody($create), true),
        );
    }

    #[Test]
    public function execute_keeps_matching_stage_unchanged(): void
    {
        $result = new WorkflowStageEnsureAction($this->createMockClient(
            $this->mockResponse('list-workflows-custom'),
            $this->mockResponse('list-workflow-stages-article'),
        ))->execute('680', '200', 'Approved', color: '#1bb300', allowPublish: true, nextStages: [WorkflowStageEnsureAction::ALL], afterPublish: 'Writing');

        $this->assertSame(WorkflowStageEnsureResult::UNCHANGED, $result->status);
    }

    #[Test]
    public function execute_sends_only_changed_fields_and_reads_the_stage_back(): void
    {
        $update = new MockResponse('', ['http_code' => 204]);
        $read = $this->mockResponse('one-workflow-stage');
        $result = new WorkflowStageEnsureAction($this->createMockClient(
            $this->mockResponse('list-workflows-custom'),
            $this->mockResponse('list-workflow-stages-article'),
            $update,
            $read,
        ))->execute('680', 'Article review', 'Writing', color: '#babcb6', nextStages: ['Writing', 'Approved']);

        $this->assertSame(WorkflowStageEnsureResult::UPDATED, $result->status);
        $this->assertSame(['workflow_stage_ids'], $result->changedFields);
        $this->assertStringEndsWith('/v1/spaces/680/workflow_stages/201', $update->getRequestUrl());
        $this->assertSame(['workflow_stage' => ['workflow_stage_ids' => [201, 202]]], json_decode($this->requestBody($update), true));
        $this->assertStringEndsWith('/v1/spaces/680/workflow_stages/201', $read->getRequestUrl());
    }

    #[Test]
    public function execute_unsets_the_current_default_before_setting_a_new_one(): void
    {
        $unset = new MockResponse('', ['http_code' => 204]);
        $set = new MockResponse('', ['http_code' => 204]);
        new WorkflowStageEnsureAction($this->createMockClient(
            $this->mockResponse('list-workflows-custom'),
            $this->mockResponse('list-workflow-stages-article'),
            $unset,
            $set,
            $this->mockResponse('one-workflow-stage'),
        ))->execute('680', 'Article review', 'Approved', isDefault: true);

        $this->assertStringEndsWith('/workflow_stages/201', $unset->getRequestUrl());
        $this->assertSame(['workflow_stage' => ['is_default' => false]], json_decode($this->requestBody($unset), true));
        $this->assertStringEndsWith('/workflow_stages/202', $set->getRequestUrl());
        $this->assertSame(['workflow_stage' => ['is_default' => true]], json_decode($this->requestBody($set), true));
    }

    #[Test]
    public function execute_throws_on_unknown_next_stage(): void
    {
        $action = new WorkflowStageEnsureAction($this->createMockClient(
            $this->mockResponse('list-workflows-custom'),
            $this->mockResponse('list-workflow-stages-article'),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Workflow stage "Published" not found in workflow "Article review".');

        $action->execute('680', 'Article review', 'Writing', nextStages: ['Published']);
    }

    #[Test]
    public function execute_throws_on_unknown_workflow(): void
    {
        $action = new WorkflowStageEnsureAction($this->createMockClient(
            $this->mockResponse('list-workflows-custom'),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Workflow not found: News review');

        $action->execute('680', 'News review', 'Writing');
    }

    private function requestBody(MockResponse $response): string
    {
        $body = $response->getRequestOptions()['body'] ?? '';

        return is_string($body) ? $body : '';
    }
}
