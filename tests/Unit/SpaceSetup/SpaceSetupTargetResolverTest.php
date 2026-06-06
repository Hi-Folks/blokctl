<?php

declare(strict_types=1);

namespace Tests\Unit\SpaceSetup;

use Blokctl\SpaceSetup\SpaceSetupTargetResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SpaceSetupTargetResolverTest extends TestCase
{
    #[Test]
    public function returns_existing_space_id_without_duplication(): void
    {
        $duplicateCalled = false;

        $spaceId = new SpaceSetupTargetResolver()->resolve(
            existingSpaceId: '123456',
            duplicateFrom: null,
            newSpaceName: null,
            dryRun: false,
            duplicate: function () use (&$duplicateCalled): string {
                $duplicateCalled = true;
                return 'not-used';
            },
        );

        $this->assertSame('123456', $spaceId);
        $this->assertFalse($duplicateCalled);
    }

    #[Test]
    public function returns_placeholder_without_duplicating_during_dry_run(): void
    {
        $duplicateCalled = false;

        $spaceId = new SpaceSetupTargetResolver()->resolve(
            existingSpaceId: null,
            duplicateFrom: 'template-id',
            newSpaceName: 'Customer Demo',
            dryRun: true,
            duplicate: function () use (&$duplicateCalled): string {
                $duplicateCalled = true;
                return 'not-used';
            },
        );

        $this->assertSame(SpaceSetupTargetResolver::DRY_RUN_SPACE_ID, $spaceId);
        $this->assertFalse($duplicateCalled);
    }

    #[Test]
    public function duplicates_and_returns_created_space_id(): void
    {
        $spaceId = new SpaceSetupTargetResolver()->resolve(
            existingSpaceId: null,
            duplicateFrom: 'template-id',
            newSpaceName: 'Customer Demo',
            dryRun: false,
            duplicate: function (string $sourceSpaceId, string $name): string {
                $this->assertSame('template-id', $sourceSpaceId);
                $this->assertSame('Customer Demo', $name);
                return 'created-id';
            },
        );

        $this->assertSame('created-id', $spaceId);
    }

    #[Test]
    public function rejects_existing_space_and_duplicate_source_together(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Use either --space-id (-S) or --duplicate-from, not both.');

        new SpaceSetupTargetResolver()->resolve(
            existingSpaceId: '123456',
            duplicateFrom: 'template-id',
            newSpaceName: 'Customer Demo',
            dryRun: true,
            duplicate: static fn(): string => 'not-used',
        );
    }

    #[Test]
    public function requires_name_when_duplicating(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('--name is required when using --duplicate-from.');

        new SpaceSetupTargetResolver()->resolve(
            existingSpaceId: null,
            duplicateFrom: 'template-id',
            newSpaceName: null,
            dryRun: true,
            duplicate: static fn(): string => 'not-used',
        );
    }
}
