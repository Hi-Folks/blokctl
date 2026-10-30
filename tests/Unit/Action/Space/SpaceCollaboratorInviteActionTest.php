<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Space;

use Blokctl\Action\Space\SpaceCollaboratorInviteAction;
use Blokctl\Action\Space\SpaceCollaboratorInviteResult;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\TestCase;

final class SpaceCollaboratorInviteActionTest extends TestCase
{
    #[Test]
    public function preflight_skips_existing_collaborators_and_resolves_custom_role_by_name(): void
    {
        $action = new SpaceCollaboratorInviteAction($this->createMockClient(
            $this->mockResponse('list-space-roles'),
            $this->mockResponse('list-collaborators'),
        ));

        $result = $action->preflight('680', ['ALICE@example.com', 'new.editor@example.com', 'new.editor@example.com'], 'Editors - North');

        $this->assertSame('Editors - North', $result->roleName);
        $this->assertSame(236218588949901, $result->spaceRoleId);
        $this->assertSame(['alice@example.com'], $result->emailsWithStatus(SpaceCollaboratorInviteResult::SKIPPED));
        $this->assertSame(['new.editor@example.com'], $result->emailsWithStatus(SpaceCollaboratorInviteResult::PLANNED));
    }

    #[Test]
    public function preflight_resolves_custom_role_by_id(): void
    {
        $action = new SpaceCollaboratorInviteAction($this->createMockClient(
            $this->mockResponse('list-space-roles'),
            $this->mockResponse('empty-collaborators'),
        ));

        $result = $action->preflight('680', ['new.editor@example.com'], '236218588949864');

        $this->assertSame('Page Readers', $result->roleName);
        $this->assertSame(236218588949864, $result->spaceRoleId);
    }

    #[Test]
    public function preflight_keeps_built_in_role_without_fetching_roles(): void
    {
        $action = new SpaceCollaboratorInviteAction($this->createMockClient(
            $this->mockResponse('empty-collaborators'),
        ));

        $result = $action->preflight('680', ['new.editor@example.com'], 'Admin');

        $this->assertSame('admin', $result->roleName);
        $this->assertNull($result->spaceRoleId);
    }

    #[Test]
    public function preflight_throws_on_unknown_role(): void
    {
        $action = new SpaceCollaboratorInviteAction($this->createMockClient(
            $this->mockResponse('list-space-roles'),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Space role not found: Editors - East');

        $action->preflight('680', ['new.editor@example.com'], 'Editors - East');
    }

    #[Test]
    public function preflight_throws_on_invalid_email(): void
    {
        $action = new SpaceCollaboratorInviteAction($this->createMockClient());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid email address: not-an-email');

        $action->preflight('680', ['not-an-email']);
    }

    #[Test]
    public function execute_sends_custom_role_and_reports_pending_invitation(): void
    {
        $invite = $this->mockResponse('one-collaborator-invited', 201);
        $action = new SpaceCollaboratorInviteAction($this->createMockClient($invite));
        $preflight = new SpaceCollaboratorInviteResult('Editors - North', 236218588949901, [
            ['email' => 'alice@example.com', 'status' => SpaceCollaboratorInviteResult::SKIPPED, 'message' => ''],
            ['email' => 'new.editor@example.com', 'status' => SpaceCollaboratorInviteResult::PLANNED, 'message' => ''],
        ]);

        $result = $action->execute('680', $preflight);

        $this->assertSame('POST', $invite->getRequestMethod());
        $this->assertStringEndsWith('/v1/spaces/680/collaborators/', $invite->getRequestUrl());
        $this->assertSame(
            ['email' => 'new.editor@example.com', 'role' => '236218588949901', 'space_role_id' => 236218588949901],
            json_decode($this->requestBody($invite), true),
        );
        $this->assertSame(['new.editor@example.com'], $result->emailsWithStatus(SpaceCollaboratorInviteResult::INVITED));
        $this->assertSame(['alice@example.com'], $result->emailsWithStatus(SpaceCollaboratorInviteResult::SKIPPED));
    }

    #[Test]
    public function execute_sends_built_in_role_and_reports_added_user(): void
    {
        $invite = $this->mockResponse('one-collaborator-added', 201);
        $action = new SpaceCollaboratorInviteAction($this->createMockClient($invite));
        $preflight = new SpaceCollaboratorInviteResult('editor', null, [
            ['email' => 'carol@example.com', 'status' => SpaceCollaboratorInviteResult::PLANNED, 'message' => ''],
        ]);

        $result = $action->execute('680', $preflight);

        $this->assertSame(['email' => 'carol@example.com', 'role' => 'editor'], json_decode($this->requestBody($invite), true));
        $this->assertSame(['carol@example.com'], $result->emailsWithStatus(SpaceCollaboratorInviteResult::ADDED));
        $this->assertFalse($result->hasFailures());
    }

    #[Test]
    public function execute_collects_failures_per_email(): void
    {
        $action = new SpaceCollaboratorInviteAction($this->createMockClient(
            $this->mockResponse('empty-collaborators', 422),
            $this->mockResponse('one-collaborator-added', 201),
        ));
        $preflight = new SpaceCollaboratorInviteResult('editor', null, [
            ['email' => 'broken@example.com', 'status' => SpaceCollaboratorInviteResult::PLANNED, 'message' => ''],
            ['email' => 'carol@example.com', 'status' => SpaceCollaboratorInviteResult::PLANNED, 'message' => ''],
        ]);

        $result = $action->execute('680', $preflight);

        $this->assertTrue($result->hasFailures());
        $this->assertSame(['broken@example.com'], $result->emailsWithStatus(SpaceCollaboratorInviteResult::FAILED));
        $this->assertSame(['carol@example.com'], $result->emailsWithStatus(SpaceCollaboratorInviteResult::ADDED));
    }

    private function requestBody(MockResponse $response): string
    {
        $body = $response->getRequestOptions()['body'] ?? '';

        return is_string($body) ? $body : '';
    }
}
