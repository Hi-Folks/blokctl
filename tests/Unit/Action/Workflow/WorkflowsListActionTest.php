<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Workflow;

use Blokctl\Action\Workflow\WorkflowsListAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WorkflowsListActionTest extends TestCase
{
    #[Test]
    public function execute_returns_workflows_with_stages(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-workflows'),        // WorkflowApi->list
            $this->mockResponse('list-workflow-stages'),   // WorkflowStageApi->list (1st workflow)
            $this->mockResponse('list-workflow-stages'),   // WorkflowStageApi->list (2nd workflow)
        );

        $action = new WorkflowsListAction($client);
        $result = $action->execute('680');

        $this->assertSame(2, $result->count());

        // First workflow
        $this->assertSame(12345, $result->workflows[0]['id']);
        $this->assertSame('Article ', $result->workflows[0]['name']);
        $this->assertFalse($result->workflows[0]['isDefault']);
        $this->assertCount(2, $result->workflows[0]['stages']);
        $this->assertSame(653554, $result->workflows[0]['stages'][0]['id']);
        $this->assertSame('Drafting', $result->workflows[0]['stages'][0]['name']);
        $this->assertSame(653555, $result->workflows[0]['stages'][1]['id']);
        $this->assertSame('Review', $result->workflows[0]['stages'][1]['name']);

        // Second workflow (default)
        $this->assertSame(12346, $result->workflows[1]['id']);
        $this->assertSame('Default one', $result->workflows[1]['name']);
        $this->assertTrue($result->workflows[1]['isDefault']);
    }

    #[Test]
    public function execute_returns_empty_when_no_workflows(): void
    {
        $emptyJson = json_encode(['workflows' => []], JSON_THROW_ON_ERROR);

        $client = $this->createMockClient(
            new \Symfony\Component\HttpClient\Response\MockResponse(
                $emptyJson,
                ['http_code' => 200],
            ),
        );

        $action = new WorkflowsListAction($client);
        $result = $action->execute('680');

        $this->assertSame(0, $result->count());
        $this->assertSame([], $result->workflows);
    }
}
