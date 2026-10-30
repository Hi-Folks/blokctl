<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Story;

use Blokctl\Action\Story\StoryResolveIdAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class StoryResolveIdActionTest extends TestCase
{
    #[Test]
    public function execute_returns_numeric_references_without_api_call(): void
    {
        $action = new StoryResolveIdAction($this->createMockClient());

        $this->assertSame(123456, $action->execute('680', '123456'));
        $this->assertSame(42, $action->execute('680', 42));
    }

    #[Test]
    public function execute_resolves_full_slug(): void
    {
        $response = $this->mockResponse('list-stories-folder-north');
        $action = new StoryResolveIdAction($this->createMockClient($response));

        $this->assertSame(235648969216038, $action->execute('680', 'regions/north'));
        $this->assertStringContainsString('with_slug=regions/north', $response->getRequestUrl());
    }

    #[Test]
    public function execute_throws_when_slug_is_not_found(): void
    {
        $action = new StoryResolveIdAction($this->createMockClient(
            $this->mockResponse('empty-stories'),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No story or folder found with slug: regions/west');

        $action->execute('680', 'regions/west');
    }

    #[Test]
    public function execute_rejects_empty_reference(): void
    {
        $this->expectException(\RuntimeException::class);

        new StoryResolveIdAction($this->createMockClient())->execute('680', ' ');
    }

    #[Test]
    public function execute_many_keeps_order_and_removes_duplicates(): void
    {
        $action = new StoryResolveIdAction($this->createMockClient(
            $this->mockResponse('list-stories-folder-north'),
        ));

        $this->assertSame(
            [42, 235648969216038],
            $action->executeMany('680', [42, 'regions/north', '235648969216038', '42']),
        );
    }
}
