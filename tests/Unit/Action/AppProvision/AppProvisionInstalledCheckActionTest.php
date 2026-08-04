<?php

declare(strict_types=1);

namespace Tests\Unit\Action\AppProvision;

use Blokctl\Action\AppProvision\AppProvisionInstalledCheckAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AppProvisionInstalledCheckActionTest extends TestCase
{
    #[Test]
    public function detects_installed_app_by_slug(): void
    {
        $action = new AppProvisionInstalledCheckAction($this->createMockClient(
            $this->mockResponse('list-app-provisions'),
        ));

        $this->assertTrue($action->isInstalled('680', 'activity'));
    }

    #[Test]
    public function throws_when_required_app_is_missing(): void
    {
        $action = new AppProvisionInstalledCheckAction($this->createMockClient(
            $this->mockResponse('list-app-provisions'),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Required app is not installed: translatable-slugs');

        $action->requireInstalled('680', 'translatable-slugs');
    }
}
