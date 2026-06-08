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
            createNew: false,
            duplicateFrom: null,
            newSpaceName: null,
            dryRun: false,
            create: static fn(): string => 'not-used',
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
            createNew: false,
            duplicateFrom: 'template-id',
            newSpaceName: 'Customer Demo',
            dryRun: true,
            create: static fn(): string => 'not-used',
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
            createNew: false,
            duplicateFrom: 'template-id',
            newSpaceName: 'Customer Demo',
            dryRun: false,
            create: static fn(): string => 'not-used',
            duplicate: function (string $sourceSpaceId, string $name): string {
                $this->assertSame('template-id', $sourceSpaceId);
                $this->assertSame('Customer Demo', $name);
                return 'created-id';
            },
        );

        $this->assertSame('created-id', $spaceId);
    }

    #[Test]
    public function creates_and_returns_blank_space_id(): void
    {
        $spaceId = new SpaceSetupTargetResolver()->resolve(
            existingSpaceId: null,
            createNew: true,
            duplicateFrom: null,
            newSpaceName: 'Customer Demo',
            dryRun: false,
            create: function (string $name): string {
                $this->assertSame('Customer Demo', $name);
                return 'created-id';
            },
            duplicate: static fn(): string => 'not-used',
        );

        $this->assertSame('created-id', $spaceId);
    }

    #[Test]
    public function returns_placeholder_without_creating_blank_space_during_dry_run(): void
    {
        $createCalled = false;

        $spaceId = new SpaceSetupTargetResolver()->resolve(
            existingSpaceId: null,
            createNew: true,
            duplicateFrom: null,
            newSpaceName: 'Customer Demo',
            dryRun: true,
            create: function () use (&$createCalled): string {
                $createCalled = true;
                return 'not-used';
            },
            duplicate: static fn(): string => 'not-used',
        );

        $this->assertSame(SpaceSetupTargetResolver::DRY_RUN_SPACE_ID, $spaceId);
        $this->assertFalse($createCalled);
    }

    #[Test]
    public function rejects_multiple_target_modes(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Use exactly one target mode');

        new SpaceSetupTargetResolver()->resolve(
            existingSpaceId: '123456',
            createNew: true,
            duplicateFrom: 'template-id',
            newSpaceName: 'Customer Demo',
            dryRun: true,
            create: static fn(): string => 'not-used',
            duplicate: static fn(): string => 'not-used',
        );
    }

    #[Test]
    public function rejects_existing_space_with_blank_space_creation(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Use exactly one target mode');

        new SpaceSetupTargetResolver()->resolve(
            existingSpaceId: '123456',
            createNew: true,
            duplicateFrom: null,
            newSpaceName: 'Customer Demo',
            dryRun: true,
            create: static fn(): string => 'not-used',
            duplicate: static fn(): string => 'not-used',
        );
    }

    #[Test]
    public function requires_name_when_duplicating(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('space.name is required when using space.duplicate_from.');

        new SpaceSetupTargetResolver()->resolve(
            existingSpaceId: null,
            createNew: false,
            duplicateFrom: 'template-id',
            newSpaceName: null,
            dryRun: true,
            create: static fn(): string => 'not-used',
            duplicate: static fn(): string => 'not-used',
        );
    }

    #[Test]
    public function requires_name_when_creating_blank_space(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('space.name is required when using space.create_new: true.');

        new SpaceSetupTargetResolver()->resolve(
            existingSpaceId: null,
            createNew: true,
            duplicateFrom: null,
            newSpaceName: null,
            dryRun: true,
            create: static fn(): string => 'not-used',
            duplicate: static fn(): string => 'not-used',
        );
    }

    #[Test]
    public function requires_an_explicit_target_mode(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Provide --space-id (-S), configure space.create_new: true, or configure space.duplicate_from.');

        new SpaceSetupTargetResolver()->resolve(
            existingSpaceId: null,
            createNew: false,
            duplicateFrom: null,
            newSpaceName: 'Customer Demo',
            dryRun: true,
            create: static fn(): string => 'not-used',
            duplicate: static fn(): string => 'not-used',
        );
    }
}
