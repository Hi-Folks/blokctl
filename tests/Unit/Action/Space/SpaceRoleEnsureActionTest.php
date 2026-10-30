<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Space;

use Blokctl\Action\Space\SpaceRoleEnsureAction;
use Blokctl\Action\Space\SpaceRoleEnsureResult;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\TestCase;

final class SpaceRoleEnsureActionTest extends TestCase
{
    #[Test]
    public function execute_creates_missing_role_with_declared_properties(): void
    {
        $create = $this->mockResponse('one-space-role', 201);
        $client = $this->createMockClient(
            $this->mockResponse('list-space-roles-empty'),
            $create,
        );

        $result = new SpaceRoleEnsureAction($client)->execute(
            spaceId: '680',
            name: 'Editors - South',
            permissions: ['read_stories', 'save_stories', 'read_stories'],
            allowedPaths: ['235648969216040'],
        );

        $this->assertSame(SpaceRoleEnsureResult::CREATED, $result->status);
        $this->assertSame(236218588950000, $result->roleId());
        $this->assertSame(['permissions', 'allowed_paths'], $result->changedFields);

        $this->assertSame('POST', $create->getRequestMethod());
        $this->assertStringEndsWith('/v1/spaces/680/space_roles', $create->getRequestUrl());
        $this->assertSame([
            'space_role' => [
                'role' => 'Editors - South',
                'permissions' => ['read_stories', 'save_stories'],
                'allowed_paths' => [235648969216040],
            ],
        ], $this->requestPayload($create));
    }

    #[Test]
    public function execute_leaves_matching_role_unchanged(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-space-roles'),
        );

        $result = new SpaceRoleEnsureAction($client)->execute(
            spaceId: '680',
            name: 'Editors - North',
            subtitle: 'North region editors',
            permissions: ['view_folders', 'view_content', 'save_stories', 'read_stories'],
            allowedPaths: [235648969216038],
        );

        $this->assertSame(SpaceRoleEnsureResult::UNCHANGED, $result->status);
        $this->assertFalse($result->changed());
        $this->assertSame([], $result->changedFields);
    }

    #[Test]
    public function execute_updates_only_changed_declared_properties(): void
    {
        $update = $this->mockResponse('one-space-role');
        $client = $this->createMockClient(
            $this->mockResponse('list-stories-folder-north'),
            $this->mockResponse('list-space-roles'),
            $update,
        );

        $result = new SpaceRoleEnsureAction($client)->execute(
            spaceId: '680',
            name: 'Editors - North',
            permissions: ['read_stories', 'save_stories', 'publish_stories'],
            allowedPaths: ['regions/north'],
        );

        $this->assertSame(SpaceRoleEnsureResult::UPDATED, $result->status);
        $this->assertSame(['permissions'], $result->changedFields);
        $this->assertSame('PUT', $update->getRequestMethod());
        $this->assertStringEndsWith('/v1/spaces/680/space_roles/236218588949901', $update->getRequestUrl());
        $this->assertSame([
            'space_role' => [
                'role' => 'Editors - North',
                'permissions' => ['read_stories', 'save_stories', 'publish_stories'],
            ],
        ], $this->requestPayload($update));
    }

    #[Test]
    public function execute_rejects_empty_name(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Space role name cannot be empty.');

        new SpaceRoleEnsureAction($this->createMockClient())->execute('680', '  ');
    }

    #[Test]
    public function execute_throws_when_save_fails(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-space-roles-empty'),
            new MockResponse('{"error":"Forbidden"}', ['http_code' => 403]),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to save space role "Editors - South"');

        new SpaceRoleEnsureAction($client)->execute('680', 'Editors - South', permissions: ['read_stories']);
    }

    /**
     * @return array<mixed>
     */
    private function requestPayload(MockResponse $response): array
    {
        $body = $response->getRequestOptions()['body'];
        $this->assertIsString($body);
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }
}
