<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Datasource;

use Blokctl\Action\Datasource\DatasourceEnsureAction;
use Blokctl\Action\Datasource\DatasourceEnsureResult;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\TestCase;

final class DatasourceEnsureActionTest extends TestCase
{
    #[Test]
    public function execute_creates_missing_datasource_with_dimensions(): void
    {
        $create = $this->mockResponse('one-datasource', 201);
        $result = new DatasourceEnsureAction($this->createMockClient(
            $this->mockResponse('list-datasources'),
            $create,
        ))->execute('680', 'countries', dimensions: ['it' => 'Italian']);

        $this->assertSame(DatasourceEnsureResult::CREATED, $result->status);
        $this->assertSame(['name', 'slug', 'dimensions'], $result->changedFields);
        $this->assertSame(
            ['datasource' => ['name' => 'countries', 'slug' => 'countries', 'dimensions_attributes' => [['name' => 'Italian', 'entry_value' => 'it']]]],
            json_decode($this->requestBody($create), true),
        );
    }

    #[Test]
    public function execute_keeps_matching_datasource_unchanged(): void
    {
        $result = new DatasourceEnsureAction($this->createMockClient(
            $this->mockResponse('list-datasources'),
        ))->execute('680', 'colors', 'Colors', ['it' => 'Italian']);

        $this->assertSame(DatasourceEnsureResult::UNCHANGED, $result->status);
        $this->assertSame(900, $result->datasourceId());
    }

    #[Test]
    public function execute_adds_new_dimensions_and_renames_existing_ones_by_id(): void
    {
        $update = $this->mockResponse('one-datasource');
        $result = new DatasourceEnsureAction($this->createMockClient(
            $this->mockResponse('list-datasources'),
            $update,
        ))->execute('680', 'colors', dimensions: ['it' => 'Italiano', 'de' => 'German']);

        $this->assertSame(DatasourceEnsureResult::UPDATED, $result->status);
        $this->assertSame(['dimensions'], $result->changedFields);
        $this->assertStringEndsWith('/v1/spaces/680/datasources/900', $update->getRequestUrl());
        $this->assertSame(
            ['datasource' => ['dimensions_attributes' => [['id' => 901, 'name' => 'Italiano'], ['name' => 'German', 'entry_value' => 'de']]]],
            json_decode($this->requestBody($update), true),
        );
    }

    #[Test]
    public function execute_reports_validation_details_on_failure(): void
    {
        $action = new DatasourceEnsureAction($this->createMockClient(
            $this->mockResponse('list-datasources'),
            new MockResponse('{"slug":["has already been taken"]}', ['http_code' => 422]),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to save datasource "countries": slug: has already been taken');

        $action->execute('680', 'countries');
    }

    private function requestBody(MockResponse $response): string
    {
        $body = $response->getRequestOptions()['body'] ?? '';

        return is_string($body) ? $body : '';
    }
}
