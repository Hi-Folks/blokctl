<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Workflow;

use Blokctl\Action\Workflow\WorkflowEnsureAction;
use Blokctl\Action\Workflow\WorkflowEnsureResult;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\TestCase;

final class WorkflowEnsureActionTest extends TestCase
{
    #[Test]
    public function execute_creates_missing_workflow(): void
    {
        $create = $this->mockResponse('one-workflow', 201);
        $result = new WorkflowEnsureAction($this->createMockClient(
            $this->mockResponse('list-workflows-custom'),
            $create,
        ))->execute('680', 'News review', ['news']);

        $this->assertSame(WorkflowEnsureResult::CREATED, $result->status);
        $this->assertSame('POST', $create->getRequestMethod());
        $this->assertSame(
            ['workflow' => ['name' => 'News review', 'content_types' => ['news']]],
            json_decode($this->requestBody($create), true),
        );
    }

    #[Test]
    public function execute_keeps_matching_workflow_unchanged(): void
    {
        $result = new WorkflowEnsureAction($this->createMockClient(
            $this->mockResponse('list-workflows-custom'),
        ))->execute('680', 'Article review', ['article-page']);

        $this->assertSame(WorkflowEnsureResult::UNCHANGED, $result->status);
        $this->assertSame(200, $result->workflowId());
        $this->assertFalse($result->changed());
    }

    #[Test]
    public function execute_updates_content_types_and_reads_the_workflow_back(): void
    {
        $update = new MockResponse('', ['http_code' => 204]);
        $result = new WorkflowEnsureAction($this->createMockClient(
            $this->mockResponse('list-workflows-custom'),
            $update,
            $this->mockResponse('one-workflow'),
        ))->execute('680', 'Article review', ['article-page', 'news']);

        $this->assertSame(WorkflowEnsureResult::UPDATED, $result->status);
        $this->assertSame(['content_types'], $result->changedFields);
        $this->assertSame('PUT', $update->getRequestMethod());
        $this->assertStringEndsWith('/v1/spaces/680/workflows/200', $update->getRequestUrl());
        $this->assertSame(['article-page', 'news'], $result->workflow['content_types'] ?? null);
    }

    #[Test]
    public function execute_rejects_content_type_used_by_another_workflow(): void
    {
        $action = new WorkflowEnsureAction($this->createMockClient(
            $this->mockResponse('list-workflows-custom'),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Content type "article-page" already belongs to workflow "Article review".');

        $action->execute('680', 'Pages', ['page', 'article-page']);
    }

    #[Test]
    public function execute_requires_content_types_to_create(): void
    {
        $action = new WorkflowEnsureAction($this->createMockClient(
            $this->mockResponse('list-workflows-custom'),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('At least one content type is required to create it.');

        $action->execute('680', 'News review');
    }

    private function requestBody(MockResponse $response): string
    {
        $body = $response->getRequestOptions()['body'] ?? '';

        return is_string($body) ? $body : '';
    }
}
