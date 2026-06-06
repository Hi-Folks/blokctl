<?php

declare(strict_types=1);

namespace Tests\Unit\SpaceSetup;

use Blokctl\SpaceSetup\SpaceSetupOperationResult;
use Blokctl\SpaceSetup\SpaceSetupOperationStatus;
use Blokctl\SpaceSetup\SpaceSetupReporter;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

use function Termwind\renderUsing;

final class SpaceSetupReporterTest extends TestCase
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
    public function dry_run_records_operations_as_planned(): void
    {
        $reporter = new SpaceSetupReporter(true);

        $reporter->run(
            'Install app: backups',
            SpaceSetupOperationStatus::Installed,
            false,
            static fn(): null => null,
        );

        $result = $reporter->results()[0];
        $this->assertSame(SpaceSetupOperationStatus::Planned, $result->status);
        $this->assertSame('Install app: backups', $result->label);
        $this->assertFalse($reporter->hasFailures());
    }

    #[Test]
    public function real_run_records_success_and_structured_results(): void
    {
        $reporter = new SpaceSetupReporter(false);

        $reporter->run(
            'Install app: backups',
            SpaceSetupOperationStatus::Installed,
            false,
            static fn(): null => null,
        );
        $reporter->run(
            'Remove demo mode',
            SpaceSetupOperationStatus::Removed,
            false,
            static fn(): SpaceSetupOperationResult => new SpaceSetupOperationResult(
                SpaceSetupOperationStatus::Skipped,
                'Remove demo mode',
                'Already removed.',
            ),
        );

        $results = $reporter->results();
        $this->assertSame(SpaceSetupOperationStatus::Installed, $results[0]->status);
        $this->assertSame(SpaceSetupOperationStatus::Skipped, $results[1]->status);
        $this->assertSame('Already removed.', $results[1]->detail);
        $this->assertFalse($reporter->hasFailures());
    }

    #[Test]
    public function records_failure_and_continues_when_configured(): void
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

        $result = $reporter->results()[0];
        $this->assertSame(SpaceSetupOperationStatus::Failed, $result->status);
        $this->assertSame('App unavailable.', $result->detail);
        $this->assertTrue($reporter->hasFailures());
    }
}
