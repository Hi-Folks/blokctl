<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Component;

use Blokctl\Action\Component\ComponentsListAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ComponentsListActionTest extends TestCase
{
    #[Test]
    public function execute_returns_components(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-components'), // ComponentApi->all
        );

        $action = new ComponentsListAction($client);
        $result = $action->execute('680');

        $this->assertSame(5, $result->count());
    }
}
