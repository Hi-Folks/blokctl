<?php

declare(strict_types=1);

namespace Tests\Unit\Action\AppProvision;

use Blokctl\Action\AppProvision\AppProvisionListAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AppProvisionListActionTest extends TestCase
{
    #[Test]
    public function execute_returns_provisions(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-app-provisions'), // AppProvisionApi->page
        );

        $action = new AppProvisionListAction($client);
        $result = $action->execute('680');

        $this->assertSame(1, $result->count());
    }
}
