<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Datasource;

use Blokctl\Action\Datasource\DatasourceEntriesCsv;
use Blokctl\Action\Datasource\DatasourceEntriesEnsureAction;
use Blokctl\Action\Datasource\DatasourceEntriesEnsureResult;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\TestCase;

final class DatasourceEntriesEnsureActionTest extends TestCase
{
    #[Test]
    public function execute_creates_updates_and_skips_entries(): void
    {
        $create = $this->mockResponse('one-datasource-entry', 201);
        $createDimension = new MockResponse('', ['http_code' => 204]);
        $updateValue = new MockResponse('', ['http_code' => 204]);
        $result = new DatasourceEntriesEnsureAction($this->createMockClient(
            $this->mockResponse('list-datasources'),
            $this->mockResponse('list-datasource-entries'),
            $this->mockResponse('list-datasource-entries-it'),
            $updateValue,
            $create,
            $createDimension,
        ))->execute('680', 'colors', [
            ['name' => 'red', 'value' => 'Crimson', 'dimensions' => ['it' => 'Rosso']],
            ['name' => 'green', 'value' => 'Green', 'dimensions' => ['it' => 'Verde']],
            ['name' => 'blue', 'value' => 'Blue'],
        ]);

        $this->assertSame(
            [DatasourceEntriesEnsureResult::UPDATED, DatasourceEntriesEnsureResult::CREATED, DatasourceEntriesEnsureResult::UNCHANGED],
            array_column($result->entries, 'status'),
        );
        $this->assertSame(['value'], $result->entries[0]['changed']);
        $this->assertStringEndsWith('/datasource_entries/911', $updateValue->getRequestUrl());
        $this->assertSame(['datasource_entry' => ['value' => 'Crimson']], json_decode($this->requestBody($updateValue), true));
        $this->assertSame(
            ['datasource_entry' => ['datasource_id' => 900, 'name' => 'green', 'value' => 'Green']],
            json_decode($this->requestBody($create), true),
        );
        $this->assertStringEndsWith('/datasource_entries/913', $createDimension->getRequestUrl());
        $this->assertSame(
            ['datasource_entry' => ['dimension_value' => 'Verde'], 'dimension_id' => 901],
            json_decode($this->requestBody($createDimension), true),
        );
    }

    #[Test]
    public function execute_changes_only_the_dimension_value_when_value_is_omitted(): void
    {
        $update = new MockResponse('', ['http_code' => 204]);
        $result = new DatasourceEntriesEnsureAction($this->createMockClient(
            $this->mockResponse('list-datasources'),
            $this->mockResponse('list-datasource-entries'),
            $this->mockResponse('list-datasource-entries-it'),
            $update,
        ))->execute('680', 'colors', [['name' => 'blue', 'dimensions' => ['it' => 'Blu']]]);

        $this->assertSame(['dimension:it'], $result->entries[0]['changed']);
        $this->assertSame(['datasource_entry' => ['dimension_value' => 'Blu'], 'dimension_id' => 901], json_decode($this->requestBody($update), true));
    }

    #[Test]
    public function execute_requires_a_value_to_create_an_entry(): void
    {
        $result = new DatasourceEntriesEnsureAction($this->createMockClient(
            $this->mockResponse('list-datasources'),
            $this->mockResponse('list-datasource-entries'),
            $this->mockResponse('list-datasource-entries-it'),
        ))->execute('680', 'colors', [['name' => 'green', 'dimensions' => ['it' => 'Verde']]]);

        $this->assertSame('A value is required to create the entry.', $result->entries[0]['message']);
    }

    #[Test]
    public function execute_rejects_unknown_dimension_code(): void
    {
        $action = new DatasourceEntriesEnsureAction($this->createMockClient(
            $this->mockResponse('list-datasources'),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Dimension "fr" not found in datasource "colors".');

        $action->execute('680', 'colors', [['name' => 'red', 'value' => 'Red', 'dimensions' => ['fr' => 'Rouge']]]);
    }

    #[Test]
    public function execute_rejects_duplicate_entry_names(): void
    {
        $action = new DatasourceEntriesEnsureAction($this->createMockClient(
            $this->mockResponse('list-datasources'),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Duplicate datasource entry name: red');

        $action->execute('680', '900', [['name' => 'red', 'value' => 'Red'], ['name' => 'red', 'value' => 'Crimson']]);
    }

    #[Test]
    public function execute_collects_failures_per_entry(): void
    {
        $result = new DatasourceEntriesEnsureAction($this->createMockClient(
            $this->mockResponse('list-datasources'),
            $this->mockResponse('list-datasource-entries'),
            new MockResponse('{"error":["Name has already been taken"]}', ['http_code' => 422]),
        ))->execute('680', 'colors', [['name' => 'green', 'value' => 'Green']]);

        $this->assertTrue($result->hasFailures());
        $this->assertSame('Failed to create entry: Name has already been taken', $result->entries[0]['message']);
    }

    #[Test]
    public function csv_loads_entries_with_dimension_columns(): void
    {
        $entries = DatasourceEntriesCsv::load(__DIR__ . '/../../../Fixtures/datasource-csv/colors.csv');

        $this->assertSame([
            ['name' => 'red', 'value' => 'Red', 'dimensions' => ['it' => 'Rosso']],
            ['name' => 'blue', 'value' => 'Blue'],
        ], $entries);
    }

    #[Test]
    public function csv_requires_name_and_value_columns(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must contain the column: name');

        DatasourceEntriesCsv::load(__DIR__ . '/../../../Fixtures/datasource-csv/missing-name.csv');
    }

    private function requestBody(MockResponse $response): string
    {
        $body = $response->getRequestOptions()['body'] ?? '';

        return is_string($body) ? $body : '';
    }
}
