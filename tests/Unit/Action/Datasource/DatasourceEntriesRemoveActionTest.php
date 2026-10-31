<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Datasource;

use Blokctl\Action\Datasource\DatasourceEntriesRemoveAction;
use Blokctl\Action\Datasource\DatasourceEntriesRemoveResult;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\TestCase;

final class DatasourceEntriesRemoveActionTest extends TestCase
{
    #[Test]
    public function preflight_matches_entry_names(): void
    {
        $result = new DatasourceEntriesRemoveAction($this->createMockClient(
            $this->mockResponse('list-datasources'),
            $this->mockResponse('list-datasource-entries'),
        ))->preflight('680', 'colors', ['red', ' red ', 'green']);

        $this->assertSame([
            ['name' => 'red', 'id' => 911, 'status' => DatasourceEntriesRemoveResult::PLANNED, 'message' => ''],
            ['name' => 'green', 'id' => null, 'status' => DatasourceEntriesRemoveResult::NOT_FOUND, 'message' => ''],
        ], $result->entries);
    }

    #[Test]
    public function preflight_requires_a_name(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('At least one entry name is required.');

        new DatasourceEntriesRemoveAction($this->createMockClient())->preflight('680', 'colors', [' ']);
    }

    #[Test]
    public function execute_deletes_planned_entries_and_collects_failures(): void
    {
        $deleted = new MockResponse('', ['http_code' => 204]);
        $result = new DatasourceEntriesRemoveAction($this->createMockClient(
            $deleted,
            new MockResponse('["This record could not be found"]', ['http_code' => 404]),
        ))->execute('680', new DatasourceEntriesRemoveResult([
            ['name' => 'red', 'id' => 911, 'status' => DatasourceEntriesRemoveResult::PLANNED, 'message' => ''],
            ['name' => 'green', 'id' => null, 'status' => DatasourceEntriesRemoveResult::NOT_FOUND, 'message' => ''],
            ['name' => 'blue', 'id' => 912, 'status' => DatasourceEntriesRemoveResult::PLANNED, 'message' => ''],
        ]));

        $this->assertSame('DELETE', $deleted->getRequestMethod());
        $this->assertStringEndsWith('/v1/spaces/680/datasource_entries/911', $deleted->getRequestUrl());
        $this->assertSame(['red'], array_column($result->entriesWithStatus(DatasourceEntriesRemoveResult::REMOVED), 'name'));
        $this->assertSame('This record could not be found', $result->entriesWithStatus(DatasourceEntriesRemoveResult::FAILED)[0]['message']);
        $this->assertTrue($result->hasFailures());
    }
}
