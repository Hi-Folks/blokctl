<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Space;

use Blokctl\Action\Space\SpaceRoleDeleteAction;
use Blokctl\Action\Space\SpaceRoleDeleteResult;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SpaceRoleDeleteActionTest extends TestCase
{
    #[Test]
    public function preflight_finds_role_by_name_and_lists_assigned_collaborators(): void
    {
        $action = new SpaceRoleDeleteAction($this->createMockClient(
            $this->mockResponse('list-space-roles'),
            $this->mockResponse('list-collaborators-with-roles'),
        ));

        $result = $action->preflight('680', 'Editors - North');

        $this->assertSame(236218588949901, $result->roleId);
        $this->assertSame('Editors - North', $result->roleName);
        $this->assertSame(['alice@example.com', 'bob@example.com'], $result->assignedEmails);
        $this->assertTrue($result->isAssigned());
    }

    #[Test]
    public function preflight_finds_role_by_id(): void
    {
        $action = new SpaceRoleDeleteAction($this->createMockClient(
            $this->mockResponse('list-space-roles'),
            $this->mockResponse('empty-collaborators'),
        ));

        $result = $action->preflight('680', '236218588949864');

        $this->assertSame('Page Readers', $result->roleName);
        $this->assertFalse($result->isAssigned());
    }

    #[Test]
    public function preflight_throws_on_unknown_role(): void
    {
        $action = new SpaceRoleDeleteAction($this->createMockClient(
            $this->mockResponse('list-space-roles'),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Space role not found: Editors - East');

        $action->preflight('680', 'Editors - East');
    }

    #[Test]
    public function execute_sends_delete_request(): void
    {
        $response = $this->mockResponse('one-space-role');
        $action = new SpaceRoleDeleteAction($this->createMockClient($response));

        $action->execute('680', new SpaceRoleDeleteResult(236218588949901, 'Editors - North', []));

        $this->assertSame('DELETE', $response->getRequestMethod());
        $this->assertStringEndsWith('/v1/spaces/680/space_roles/236218588949901', $response->getRequestUrl());
    }

    #[Test]
    public function execute_throws_on_api_error(): void
    {
        $action = new SpaceRoleDeleteAction($this->createMockClient(
            $this->mockResponse('one-space-role', 404),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to delete space role "Editors - North"');

        $action->execute('680', new SpaceRoleDeleteResult(236218588949901, 'Editors - North', []));
    }
}
