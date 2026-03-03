<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Space;

use Blokctl\Action\Space\SpaceDeleteAction;
use Blokctl\Action\Space\SpaceDeleteResult;
use PHPUnit\Framework\Attributes\Test;
use Storyblok\ManagementApi\Data\Collaborators;
use Storyblok\ManagementApi\Data\Space;
use Storyblok\ManagementApi\Data\User;
use Tests\TestCase;

final class SpaceDeleteActionTest extends TestCase
{
    #[Test]
    public function preflight_fetches_space_user_and_collaborators(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-space'),           // SpaceApi->get
            $this->mockResponse('one-user-owner'),      // UserApi->me
            $this->mockResponse('empty-collaborators'), // CollaboratorApi->page
        );

        $action = new SpaceDeleteAction($client);
        $result = $action->preflight('680');

        $this->assertSame('Example Space', $result->space->name());
        $this->assertSame('1114', $result->user->id());
        $this->assertTrue($result->isOwner);
        $this->assertTrue($result->isSolo);
        $this->assertTrue($result->canDelete());
    }

    #[Test]
    public function preflight_detects_non_owner(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-space'),           // SpaceApi->get (owner_id=1114)
            $this->mockResponse('one-user'),            // UserApi->me (id=123456, not owner)
            $this->mockResponse('empty-collaborators'), // CollaboratorApi->page
        );

        $action = new SpaceDeleteAction($client);
        $result = $action->preflight('680');

        $this->assertFalse($result->isOwner);
        $this->assertTrue($result->isSolo);
        $this->assertFalse($result->canDelete());
    }

    #[Test]
    public function preflight_detects_non_solo(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-space'),           // SpaceApi->get
            $this->mockResponse('one-user-owner'),      // UserApi->me
            $this->mockResponse('list-collaborators'),  // CollaboratorApi->page (2 collaborators)
        );

        $action = new SpaceDeleteAction($client);
        $result = $action->preflight('680');

        $this->assertTrue($result->isOwner);
        $this->assertFalse($result->isSolo);
        $this->assertFalse($result->canDelete());
    }

    #[Test]
    public function execute_throws_when_cannot_delete(): void
    {
        $client = $this->createMockClient();

        $action = new SpaceDeleteAction($client);
        $preflight = new SpaceDeleteResult(
            space: new Space('Test'),
            user: User::make(['id' => 999]),
            collaborators: Collaborators::make([]),
            isOwner: false,
            isSolo: true,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('you are not the owner');

        $action->execute('680', $preflight);
    }

    #[Test]
    public function execute_throws_not_solo_message(): void
    {
        $client = $this->createMockClient();

        $action = new SpaceDeleteAction($client);
        $preflight = new SpaceDeleteResult(
            space: new Space('Test'),
            user: User::make(['id' => 999]),
            collaborators: Collaborators::make([]),
            isOwner: true,
            isSolo: false,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('other collaborators exist');

        $action->execute('680', $preflight);
    }

    #[Test]
    public function execute_calls_delete_when_can_delete(): void
    {
        $this->expectNotToPerformAssertions();

        $client = $this->createMockClient(
            $this->mockResponse('one-space'), // SpaceApi->delete response
        );

        $action = new SpaceDeleteAction($client);
        $preflight = new SpaceDeleteResult(
            space: new Space('Test'),
            user: User::make(['id' => 1114]),
            collaborators: Collaborators::make([]),
            isOwner: true,
            isSolo: true,
        );

        $action->execute('680', $preflight);
    }
}
