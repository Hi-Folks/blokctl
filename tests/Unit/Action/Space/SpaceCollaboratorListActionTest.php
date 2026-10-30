<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Space;

use Blokctl\Action\Space\SpaceCollaboratorListAction;
use Blokctl\Action\Space\SpaceCollaboratorListResult;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SpaceCollaboratorListActionTest extends TestCase
{
    #[Test]
    public function execute_returns_collaborators(): void
    {
        $response = $this->mockResponse('list-collaborators');
        $result = new SpaceCollaboratorListAction($this->createMockClient($response))->execute('680');

        $this->assertSame(2, $result->count());
        $this->assertStringContainsString('/v1/spaces/680/collaborators', $response->getRequestUrl());
        $this->assertStringContainsString('per_page=100', $response->getRequestUrl());
    }

    #[Test]
    public function find_by_email_is_case_insensitive(): void
    {
        $result = new SpaceCollaboratorListAction($this->createMockClient(
            $this->mockResponse('list-collaborators'),
        ))->execute('680');

        $this->assertSame(10001, $result->findByEmail(' Alice@Example.com ')['id'] ?? null);
        $this->assertNull($result->findByEmail('nobody@example.com'));
    }

    #[Test]
    public function email_of_falls_back_to_invitation_email(): void
    {
        $pending = ['user' => null, 'invitation' => ['email' => 'New.Editor@example.com']];

        $this->assertSame('new.editor@example.com', SpaceCollaboratorListResult::emailOf($pending));
        $this->assertTrue(SpaceCollaboratorListResult::isPending($pending));
        $this->assertFalse(SpaceCollaboratorListResult::isPending(['user' => ['real_email' => 'a@example.com'], 'invitation' => null]));
    }

    #[Test]
    public function execute_throws_on_api_error(): void
    {
        $action = new SpaceCollaboratorListAction($this->createMockClient(
            $this->mockResponse('empty-collaborators', 401),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to fetch collaborators');

        $action->execute('680');
    }
}
