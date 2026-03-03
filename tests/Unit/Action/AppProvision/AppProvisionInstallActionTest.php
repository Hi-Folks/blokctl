<?php

declare(strict_types=1);

namespace Tests\Unit\Action\AppProvision;

use Blokctl\Action\AppProvision\AppProvisionInstallAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AppProvisionInstallActionTest extends TestCase
{
    #[Test]
    public function resolve_by_slug_returns_app_id(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-apps'), // AppApi->page
        );

        $action = new AppProvisionInstallAction($client);
        $appId = $action->resolveBySlug('680', 'seo-app');

        $this->assertSame('101', $appId);
    }

    #[Test]
    public function resolve_by_slug_throws_when_not_found(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-apps'), // AppApi->page
        );

        $action = new AppProvisionInstallAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No app found with slug: nonexistent');

        $action->resolveBySlug('680', 'nonexistent');
    }

    #[Test]
    public function preflight_returns_app_options(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-apps'), // AppApi->page
        );

        $action = new AppProvisionInstallAction($client);
        $result = $action->preflight('680');

        $this->assertCount(3, $result->appOptions);
        $this->assertArrayHasKey('101', $result->appOptions);
        $this->assertSame('SEO App (seo-app)', $result->appOptions['101']);
    }

    #[Test]
    public function execute_installs_app(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-app-provision'), // AppProvisionApi->install
        );

        $action = new AppProvisionInstallAction($client);
        $provision = $action->execute('680', '14');

        $this->assertSame('Activities', $provision->name());
        $this->assertSame('activity', $provision->slug());
    }
}
