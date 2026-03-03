<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Space;

use Blokctl\Action\Space\SpacesListAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SpacesListActionTest extends TestCase
{
    #[Test]
    public function execute_returns_all_spaces(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-spaces'), // SpaceApi->all
        );

        $action = new SpacesListAction($client);
        $result = $action->execute();

        $this->assertSame(2, $result->count());
        $this->assertSame([], $result->errors);
    }

    #[Test]
    public function execute_filters_owned_only(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-spaces'),    // SpaceApi->all (both owned by 1114)
            $this->mockResponse('one-user-owner'), // UserApi->me (id=1114)
        );

        $action = new SpacesListAction($client);
        $result = $action->execute(ownedOnly: true);

        // Both spaces have owner_id=1114, user id=1114, so both pass
        $this->assertSame(2, $result->count());
    }

    #[Test]
    public function execute_filters_owned_only_excludes_non_owned(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-spaces'), // SpaceApi->all (owner_id=1114)
            $this->mockResponse('one-user'),    // UserApi->me (id=123456, not owner)
        );

        $action = new SpacesListAction($client);
        $result = $action->execute(ownedOnly: true);

        // User 123456 does not own spaces with owner_id 1114
        $this->assertSame(0, $result->count());
    }

    #[Test]
    public function execute_filters_updated_before(): void
    {
        // Spaces have updated_at "2018-11-11" which is > 90 days ago
        $client = $this->createMockClient(
            $this->mockResponse('list-spaces'),
        );

        $action = new SpacesListAction($client);
        $result = $action->execute(updatedBeforeDays: 90);

        // Both spaces were last updated in 2018, well over 90 days ago
        $this->assertSame(2, $result->count());
    }
}
