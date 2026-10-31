<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Datasource;

use Blokctl\Action\Datasource\DatasourceEntriesCsv;
use Blokctl\Action\Datasource\DatasourceEntriesExportAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DatasourceEntriesExportActionTest extends TestCase
{
    #[Test]
    public function execute_exports_entries_with_a_column_per_dimension(): void
    {
        $dimension = $this->mockResponse('list-datasource-entries-it');
        $result = new DatasourceEntriesExportAction($this->createMockClient(
            $this->mockResponse('list-datasources'),
            $dimension,
            $this->mockResponse('list-datasource-entries'),
        ))->execute('680', 'colors');

        $this->assertStringContainsString('dimension=901', $dimension->getRequestUrl());
        $this->assertSame(['name', 'value', 'it'], $result->header);
        $this->assertSame([['red', 'Red', 'Rosso'], ['blue', 'Blue', '']], $result->rows);
        $this->assertSame("name,value,it\nred,Red,Rosso\nblue,Blue,\n", $result->toCsv());
    }

    #[Test]
    public function execute_exports_only_name_and_value_without_dimensions(): void
    {
        $result = new DatasourceEntriesExportAction($this->createMockClient(
            $this->mockResponse('list-datasources'),
            $this->mockResponse('list-datasource-entries'),
        ))->execute('680', 'colors', []);

        $this->assertSame(['name', 'value'], $result->header);
        $this->assertSame(2, $result->count());
    }

    #[Test]
    public function execute_rejects_unknown_dimension_code(): void
    {
        $action = new DatasourceEntriesExportAction($this->createMockClient(
            $this->mockResponse('list-datasources'),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Dimension "fr" not found in datasource "colors".');

        $action->execute('680', 'colors', ['fr']);
    }

    #[Test]
    public function exported_csv_can_be_loaded_back(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'blokctl-csv-');
        $this->assertIsString($path);
        file_put_contents($path, DatasourceEntriesCsv::format(['name', 'value', 'it'], [['fr', 'France', 'Francia (FR)'], ['note', 'A, "quoted" value', '']]));

        try {
            $this->assertSame([
                ['name' => 'fr', 'value' => 'France', 'dimensions' => ['it' => 'Francia (FR)']],
                ['name' => 'note', 'value' => 'A, "quoted" value'],
            ], DatasourceEntriesCsv::load($path));
        } finally {
            unlink($path);
        }
    }
}
