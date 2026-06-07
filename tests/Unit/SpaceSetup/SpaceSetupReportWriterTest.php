<?php

declare(strict_types=1);

namespace Tests\Unit\SpaceSetup;

use Blokctl\SpaceSetup\SpaceSetupOperationResult;
use Blokctl\SpaceSetup\SpaceSetupOperationStatus;
use Blokctl\SpaceSetup\SpaceSetupReporter;
use Blokctl\SpaceSetup\SpaceSetupReportWriter;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

use function Termwind\renderUsing;

final class SpaceSetupReportWriterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        renderUsing(new NullOutput());
    }

    protected function tearDown(): void
    {
        renderUsing(null);
        parent::tearDown();
    }

    #[Test]
    public function writes_completed_report_with_masked_operation_details(): void
    {
        $reporter = new SpaceSetupReporter(false);
        $reporter->run(
            'Configure preview URLs',
            SpaceSetupOperationStatus::Updated,
            false,
            static fn(): SpaceSetupOperationResult => new SpaceSetupOperationResult(
                SpaceSetupOperationStatus::Updated,
                'Configure preview URLs',
                'https://demo.test/?token=secret-token&path=home',
            ),
        );

        $path = sys_get_temp_dir() . '/blokctl-space-setup-report-' . bin2hex(random_bytes(6)) . '.json';
        try {
            new SpaceSetupReportWriter()->write(
                path: $path,
                reporter: $reporter,
                dryRun: false,
                target: [
                    'space_id' => '123456',
                    'mode' => 'Existing space',
                ],
            );

            $report = $this->readReport($path);
            $target = $report['target'];
            $operations = $report['operations'];
            $summary = $report['summary'];
            $this->assertIsArray($target);
            $this->assertIsArray($operations);
            $this->assertIsArray($operations[0]);
            $this->assertIsArray($summary);
            $this->assertSame('completed', $report['status']);
            $this->assertSame('123456', $target['space_id']);
            $this->assertSame('updated', $operations[0]['status']);
            $this->assertSame('https://demo.test/?token=********&path=home', $operations[0]['detail']);
            $this->assertSame(1, $summary['updated']);
            $this->assertSame(0, $summary['failed']);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function writes_failed_report_with_partial_operations(): void
    {
        $reporter = new SpaceSetupReporter(false);
        $reporter->run(
            'Install app: unavailable',
            SpaceSetupOperationStatus::Installed,
            true,
            static function (): never {
                throw new \RuntimeException('App unavailable.');
            },
        );

        $path = sys_get_temp_dir() . '/blokctl-space-setup-report-' . bin2hex(random_bytes(6)) . '.json';
        try {
            new SpaceSetupReportWriter()->write(
                path: $path,
                reporter: $reporter,
                dryRun: false,
                target: ['space_id' => '123456'],
                duplication: [
                    'source_space_id' => '654321',
                    'performed' => true,
                    'readiness_checks' => 2,
                ],
            );

            $report = $this->readReport($path);
            $duplication = $report['duplication'];
            $operations = $report['operations'];
            $summary = $report['summary'];
            $this->assertIsArray($duplication);
            $this->assertIsArray($operations);
            $this->assertIsArray($operations[0]);
            $this->assertIsArray($summary);
            $this->assertSame('failed', $report['status']);
            $this->assertSame(2, $duplication['readiness_checks']);
            $this->assertSame('failed', $operations[0]['status']);
            $this->assertSame(1, $summary['failed']);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function writes_planned_status_for_dry_run(): void
    {
        $reporter = new SpaceSetupReporter(true);

        $path = sys_get_temp_dir() . '/blokctl-space-setup-report-' . bin2hex(random_bytes(6)) . '.json';
        try {
            new SpaceSetupReportWriter()->write($path, $reporter, true, ['space_id' => 'NEW_SPACE_ID']);

            $report = $this->readReport($path);
            $this->assertSame('planned', $report['status']);
            $this->assertTrue($report['dry_run']);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readReport(string $path): array
    {
        $content = file_get_contents($path);
        $this->assertIsString($content);

        /** @var array<string, mixed> $report */
        $report = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        return $report;
    }
}
