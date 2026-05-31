<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Experiment;

use Blokctl\Action\Experiment\ExperimentCreateAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ExperimentCreateActionTest extends TestCase
{
    #[Test]
    public function execute_creates_test_experiment(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-experiment'),
        );

        $result = (new ExperimentCreateAction($client))->execute(
            spaceId: '222',
            name: 'blokctl_probe',
            displayName: 'blokctl probe',
            description: 'POST permission probe',
            storyIds: [101],
        );

        $this->assertSame('123', $result->experiment->id());
        $this->assertSame('homepage_hero_test', $result->experiment->name());
        $this->assertSame('draft', $result->experiment->status());
    }

    #[Test]
    public function execute_allows_omitting_story_ids(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-experiment'),
        );

        $result = (new ExperimentCreateAction($client))->execute(
            spaceId: '222',
            name: 'blokctl_probe',
            displayName: 'blokctl probe',
            description: 'POST permission probe',
            storyIds: [],
        );

        $this->assertSame('123', $result->experiment->id());
        $this->assertSame('homepage_hero_test', $result->experiment->name());
    }

    #[Test]
    public function execute_includes_response_body_when_create_fails(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('experiment-result-forbidden', 403),
        );

        $action = new ExperimentCreateAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to create experiment: 403 - Forbidden. Insufficient permissions.');
        $this->expectExceptionMessage('Response body: {');

        $action->execute(
            spaceId: '222',
            name: 'blokctl_probe',
            displayName: 'blokctl probe',
            description: 'POST permission probe',
            storyIds: [101],
        );
    }
}
