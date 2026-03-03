<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Space;

use Blokctl\Action\Space\SpaceDemoRemoveAction;
use Blokctl\Action\Space\SpaceDemoRemoveResult;
use PHPUnit\Framework\Attributes\Test;
use Storyblok\ManagementApi\Data\Space;
use Tests\TestCase;

final class SpaceDemoRemoveActionTest extends TestCase
{
    #[Test]
    public function preflight_detects_demo_space(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-space-demo'),
        );

        $action = new SpaceDemoRemoveAction($client);
        $result = $action->preflight('680');

        $this->assertSame('Demo Space', $result->space->name());
        $this->assertTrue($result->isDemo);
    }

    #[Test]
    public function preflight_detects_non_demo_space(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-space'),
        );

        $action = new SpaceDemoRemoveAction($client);
        $result = $action->preflight('680');

        $this->assertSame('Example Space', $result->space->name());
        $this->assertFalse($result->isDemo);
    }

    #[Test]
    public function execute_throws_when_not_demo(): void
    {
        $client = $this->createMockClient();

        $space = new Space('Example Space');
        $space->set('id', 680);

        $preflight = new SpaceDemoRemoveResult(
            space: $space,
            isDemo: false,
        );

        $action = new SpaceDemoRemoveAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not in demo mode');

        $action->execute('680', $preflight);
    }

    #[Test]
    public function execute_removes_demo_mode(): void
    {
        $this->expectNotToPerformAssertions();

        $client = $this->createMockClient(
            $this->mockResponse('one-space'), // SpaceApi->update response
        );

        $space = new Space('Demo Space');
        $space->set('id', 680);

        $preflight = new SpaceDemoRemoveResult(
            space: $space,
            isDemo: true,
        );

        $action = new SpaceDemoRemoveAction($client);
        $action->execute('680', $preflight);
    }
}
