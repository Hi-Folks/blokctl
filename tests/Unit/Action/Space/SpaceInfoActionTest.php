<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Space;

use Blokctl\Action\Space\SpaceInfoAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SpaceInfoActionTest extends TestCase
{
    #[Test]
    public function execute_returns_space_user_and_ownership(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-space'),      // SpaceApi->get
            $this->mockResponse('one-user-owner'), // UserApi->me (id=1114, matches owner_id)
        );

        $action = new SpaceInfoAction($client);
        $result = $action->execute('680');

        $this->assertSame('Example Space', $result->space->name());
        $this->assertSame('1114', $result->user->id());
        $this->assertTrue($result->isOwner);
    }

    #[Test]
    public function execute_detects_non_owner(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-space'), // SpaceApi->get (owner_id=1114)
            $this->mockResponse('one-user'),  // UserApi->me (id=123456)
        );

        $action = new SpaceInfoAction($client);
        $result = $action->execute('680');

        $this->assertFalse($result->isOwner);
    }
}
