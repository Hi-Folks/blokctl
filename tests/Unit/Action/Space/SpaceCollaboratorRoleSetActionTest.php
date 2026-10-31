<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Space;

use Blokctl\Action\Space\SpaceCollaboratorRoleSetAction;
use Blokctl\Action\Space\SpaceCollaboratorRoleSetResult;
use Blokctl\Action\Space\SpaceRoleResolveAction;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\TestCase;

final class SpaceCollaboratorRoleSetActionTest extends TestCase
{
    #[Test]
    public function preflight_finds_collaborator_by_email_and_detects_change(): void
    {
        $action = new SpaceCollaboratorRoleSetAction($this->createMockClient(
            $this->mockResponse('list-collaborators-with-roles'),
            $this->mockResponse('list-space-roles'),
        ));

        $result = $action->preflight('680', 'BOB@example.com', 'Editors - North');

        $this->assertSame(10002, $result->collaboratorId);
        $this->assertSame('bob@example.com', $result->email);
        $this->assertSame(236218588949864, $result->previousSpaceRoleId);
        $this->assertSame(236218588949901, $result->spaceRoleId);
        $this->assertTrue($result->changed);
    }

    #[Test]
    public function preflight_finds_collaborator_by_id_and_detects_same_role(): void
    {
        $action = new SpaceCollaboratorRoleSetAction($this->createMockClient(
            $this->mockResponse('list-collaborators-with-roles'),
            $this->mockResponse('list-space-roles'),
        ));

        $result = $action->preflight('680', '10001', '236218588949901');

        $this->assertSame('alice@example.com', $result->email);
        $this->assertFalse($result->changed);
    }

    #[Test]
    public function preflight_detects_same_built_in_role(): void
    {
        $action = new SpaceCollaboratorRoleSetAction($this->createMockClient(
            $this->mockResponse('list-collaborators-with-roles'),
        ));

        $result = $action->preflight('680', 'carol@example.com', 'Editor');

        $this->assertSame('editor', $result->roleName);
        $this->assertNull($result->spaceRoleId);
        $this->assertFalse($result->changed);
    }

    #[Test]
    public function preflight_throws_when_collaborator_is_not_listed(): void
    {
        $action = new SpaceCollaboratorRoleSetAction($this->createMockClient(
            $this->mockResponse('list-collaborators-with-roles'),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Collaborator not found: nobody@example.com');

        $action->preflight('680', 'nobody@example.com', 'editor');
    }

    #[Test]
    public function execute_sends_custom_role_wrapped_in_collaborator(): void
    {
        $response = $this->mockResponse('one-collaborator-added');
        $action = new SpaceCollaboratorRoleSetAction($this->createMockClient($response));

        $action->execute('680', $this->preflight(spaceRoleId: 236218588949901, roleName: 'Editors - North'));

        $this->assertSame('PUT', $response->getRequestMethod());
        $this->assertStringEndsWith('/v1/spaces/680/collaborators/10002', $response->getRequestUrl());
        $this->assertSame(
            ['collaborator' => ['role' => '236218588949901', 'space_role_id' => 236218588949901]],
            json_decode($this->requestBody($response), true),
        );
    }

    #[Test]
    public function execute_sends_built_in_role_and_clears_custom_role(): void
    {
        $response = $this->mockResponse('one-collaborator-added');
        $action = new SpaceCollaboratorRoleSetAction($this->createMockClient($response));

        $action->execute('680', $this->preflight(spaceRoleId: null, roleName: 'editor'));

        $this->assertSame(
            ['collaborator' => ['role' => 'editor', 'space_role_id' => null]],
            json_decode($this->requestBody($response), true),
        );
    }

    #[Test]
    public function execute_skips_request_when_unchanged(): void
    {
        $action = new SpaceCollaboratorRoleSetAction($this->createMockClient());

        $action->execute('680', $this->preflight(spaceRoleId: 236218588949901, roleName: 'Editors - North', changed: false));

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function execute_throws_on_api_error(): void
    {
        $action = new SpaceCollaboratorRoleSetAction($this->createMockClient(
            $this->mockResponse('one-collaborator-added', 422),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to set the role of bob@example.com');

        $action->execute('680', $this->preflight(spaceRoleId: null, roleName: 'admin'));
    }

    #[Test]
    public function role_resolver_handles_built_in_and_custom_roles(): void
    {
        $resolver = new SpaceRoleResolveAction($this->createMockClient(
            $this->mockResponse('list-space-roles'),
        ));

        $this->assertSame(['admin', null], $resolver->execute('680', ' Admin '));
        $this->assertSame(['Page Readers', 236218588949864], $resolver->execute('680', 'Page Readers'));
    }

    private function preflight(?int $spaceRoleId, string $roleName, bool $changed = true): SpaceCollaboratorRoleSetResult
    {
        return new SpaceCollaboratorRoleSetResult(
            collaboratorId: 10002,
            email: 'bob@example.com',
            previousRole: '236218588949864',
            previousSpaceRoleId: 236218588949864,
            roleName: $roleName,
            spaceRoleId: $spaceRoleId,
            changed: $changed,
        );
    }

    private function requestBody(MockResponse $response): string
    {
        $body = $response->getRequestOptions()['body'] ?? '';

        return is_string($body) ? $body : '';
    }
}
