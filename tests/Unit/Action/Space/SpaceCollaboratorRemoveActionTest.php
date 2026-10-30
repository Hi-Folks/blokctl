<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Space;

use Blokctl\Action\Space\SpaceCollaboratorRemoveAction;
use Blokctl\Action\Space\SpaceCollaboratorRemoveResult;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SpaceCollaboratorRemoveActionTest extends TestCase
{
    #[Test]
    public function preflight_matches_emails_and_ids(): void
    {
        $action = new SpaceCollaboratorRemoveAction($this->createMockClient(
            $this->mockResponse('list-collaborators-with-roles'),
        ));

        $result = $action->preflight('680', ['Alice@Example.com', 'nobody@example.com'], ['10003', 99999]);

        $this->assertSame([
            ['email' => 'alice@example.com', 'collaboratorId' => 10001, 'status' => SpaceCollaboratorRemoveResult::PLANNED, 'message' => ''],
            ['email' => 'nobody@example.com', 'collaboratorId' => null, 'status' => SpaceCollaboratorRemoveResult::NOT_FOUND, 'message' => ''],
            ['email' => 'carol@example.com', 'collaboratorId' => 10003, 'status' => SpaceCollaboratorRemoveResult::PLANNED, 'message' => ''],
            ['email' => '', 'collaboratorId' => 99999, 'status' => SpaceCollaboratorRemoveResult::PLANNED, 'message' => ''],
        ], $result->entries);
    }

    #[Test]
    public function preflight_deduplicates_email_and_matching_id(): void
    {
        $action = new SpaceCollaboratorRemoveAction($this->createMockClient(
            $this->mockResponse('list-collaborators-with-roles'),
        ));

        $result = $action->preflight('680', ['alice@example.com'], ['10001']);

        $this->assertCount(1, $result->entries);
    }

    #[Test]
    public function preflight_requires_a_reference(): void
    {
        $action = new SpaceCollaboratorRemoveAction($this->createMockClient());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('At least one email or collaborator ID is required.');

        $action->preflight('680');
    }

    #[Test]
    public function preflight_rejects_non_numeric_id(): void
    {
        $action = new SpaceCollaboratorRemoveAction($this->createMockClient(
            $this->mockResponse('empty-collaborators'),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid collaborator ID: abc');

        $action->preflight('680', [], ['abc']);
    }

    #[Test]
    public function execute_deletes_planned_entries_and_collects_failures(): void
    {
        $removed = $this->mockResponse('empty-collaborators');
        $action = new SpaceCollaboratorRemoveAction($this->createMockClient(
            $removed,
            $this->mockResponse('empty-collaborators', 404),
        ));
        $preflight = new SpaceCollaboratorRemoveResult([
            ['email' => 'alice@example.com', 'collaboratorId' => 10001, 'status' => SpaceCollaboratorRemoveResult::PLANNED, 'message' => ''],
            ['email' => 'nobody@example.com', 'collaboratorId' => null, 'status' => SpaceCollaboratorRemoveResult::NOT_FOUND, 'message' => ''],
            ['email' => '', 'collaboratorId' => 99999, 'status' => SpaceCollaboratorRemoveResult::PLANNED, 'message' => ''],
        ]);

        $result = $action->execute('680', $preflight);

        $this->assertSame('DELETE', $removed->getRequestMethod());
        $this->assertStringEndsWith('/v1/spaces/680/collaborators/10001', $removed->getRequestUrl());
        $this->assertCount(1, $result->entriesWithStatus(SpaceCollaboratorRemoveResult::REMOVED));
        $this->assertCount(1, $result->entriesWithStatus(SpaceCollaboratorRemoveResult::NOT_FOUND));
        $this->assertSame(99999, $result->entriesWithStatus(SpaceCollaboratorRemoveResult::FAILED)[0]['collaboratorId']);
        $this->assertTrue($result->hasFailures());
    }
}
