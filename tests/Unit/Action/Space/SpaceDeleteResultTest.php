<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Space;

use Blokctl\Action\Space\SpaceDeleteResult;
use PHPUnit\Framework\Attributes\Test;
use Storyblok\ManagementApi\Data\Collaborator;
use Storyblok\ManagementApi\Data\Collaborators;
use Storyblok\ManagementApi\Data\Space;
use Storyblok\ManagementApi\Data\User;
use Tests\TestCase;

final class SpaceDeleteResultTest extends TestCase
{
    #[Test]
    public function can_delete_when_owner_and_solo(): void
    {
        $result = $this->makeResult(isOwner: true, isSolo: true);

        $this->assertTrue($result->canDelete());
    }

    #[Test]
    public function cannot_delete_when_not_owner(): void
    {
        $result = $this->makeResult(isOwner: false, isSolo: true);

        $this->assertFalse($result->canDelete());
    }

    #[Test]
    public function cannot_delete_when_not_solo(): void
    {
        $result = $this->makeResult(isOwner: true, isSolo: false);

        $this->assertFalse($result->canDelete());
    }

    #[Test]
    public function cannot_delete_when_neither_owner_nor_solo(): void
    {
        $result = $this->makeResult(isOwner: false, isSolo: false);

        $this->assertFalse($result->canDelete());
    }

    private function makeResult(bool $isOwner, bool $isSolo): SpaceDeleteResult
    {
        return new SpaceDeleteResult(
            space: new Space('Test'),
            user: User::make(['id' => 1]),
            collaborators: Collaborators::make([]),
            isOwner: $isOwner,
            isSolo: $isSolo,
        );
    }
}
