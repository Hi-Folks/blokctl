<?php

declare(strict_types=1);

namespace Tests\Unit\Action\SpacePreview;

use Blokctl\Action\SpacePreview\SpacePreviewListResult;
use PHPUnit\Framework\Attributes\Test;
use Storyblok\ManagementApi\Data\SpaceEnvironments;
use Tests\TestCase;

final class SpacePreviewListResultTest extends TestCase
{
    #[Test]
    public function has_environments_returns_false_when_empty(): void
    {
        $result = new SpacePreviewListResult(
            defaultDomain: 'https://example.com',
            environments: SpaceEnvironments::make([]),
        );

        $this->assertFalse($result->hasEnvironments());
    }

    #[Test]
    public function has_environments_returns_true_when_populated(): void
    {
        $result = new SpacePreviewListResult(
            defaultDomain: 'https://example.com',
            environments: SpaceEnvironments::make([
                ['name' => 'Staging', 'location' => 'https://staging.example.com'],
            ]),
        );

        $this->assertTrue($result->hasEnvironments());
    }
}
