<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Space;

use Blokctl\Action\Space\SpaceRoleListAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SpaceRoleListActionTest extends TestCase
{
    #[Test]
    public function execute_returns_space_roles(): void
    {
        $response = $this->mockResponse('list-space-roles');
        $result = new SpaceRoleListAction($this->createMockClient($response))->execute('680');

        $this->assertSame(2, $result->count());
        $this->assertStringEndsWith('/v1/spaces/680/space_roles', $response->getRequestUrl());
    }

    #[Test]
    public function execute_passes_search_term(): void
    {
        $response = $this->mockResponse('list-space-roles');
        new SpaceRoleListAction($this->createMockClient($response))->execute('680', 'Editors');

        $this->assertStringContainsString('search=Editors', $response->getRequestUrl());
    }

    #[Test]
    public function find_by_name_matches_exact_role_name(): void
    {
        $result = new SpaceRoleListAction($this->createMockClient(
            $this->mockResponse('list-space-roles'),
        ))->execute('680');

        $this->assertSame(236218588949901, $result->findByName('Editors - North')['id'] ?? null);
        $this->assertNull($result->findByName('Editors'));
    }

    #[Test]
    public function execute_throws_on_api_error(): void
    {
        $action = new SpaceRoleListAction($this->createMockClient(
            $this->mockResponse('empty-stories', 401),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to fetch space roles');

        $action->execute('680');
    }
}
