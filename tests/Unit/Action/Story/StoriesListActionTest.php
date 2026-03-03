<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Story;

use Blokctl\Action\Story\StoriesListAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class StoriesListActionTest extends TestCase
{
    #[Test]
    public function execute_returns_stories(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-stories'), // StoryApi->page
        );

        $action = new StoriesListAction($client);
        $result = $action->execute('680');

        $this->assertSame(2, $result->count());
    }

    #[Test]
    public function execute_returns_empty_when_no_stories(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('empty-stories'), // StoryApi->page
        );

        $action = new StoriesListAction($client);
        $result = $action->execute('680');

        $this->assertSame(0, $result->count());
    }
}
