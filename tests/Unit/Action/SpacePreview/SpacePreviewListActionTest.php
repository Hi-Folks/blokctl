<?php

declare(strict_types=1);

namespace Tests\Unit\Action\SpacePreview;

use Blokctl\Action\SpacePreview\SpacePreviewListAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SpacePreviewListActionTest extends TestCase
{
    #[Test]
    public function execute_returns_domain_and_environments(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-space'), // SpaceApi->get
        );

        $action = new SpacePreviewListAction($client);
        $result = $action->execute('680');

        $this->assertSame('https://example.storyblok.com', $result->defaultDomain);
        // The fixture has environments: null, so no environments
        $this->assertFalse($result->hasEnvironments());
    }
}
