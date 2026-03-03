<?php

declare(strict_types=1);

namespace Tests\Unit\Action\User;

use Blokctl\Action\User\UserMeAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class UserMeActionTest extends TestCase
{
    #[Test]
    public function execute_returns_user(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-user'),
        );

        $action = new UserMeAction($client);
        $result = $action->execute();

        $this->assertSame('123456', $result->user->id());
        $this->assertSame('John', $result->user->firstname());
        $this->assertSame('Doe', $result->user->lastname());
    }
}
