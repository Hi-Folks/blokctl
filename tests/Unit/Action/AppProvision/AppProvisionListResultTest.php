<?php

declare(strict_types=1);

namespace Tests\Unit\Action\AppProvision;

use Blokctl\Action\AppProvision\AppProvisionListResult;
use PHPUnit\Framework\Attributes\Test;
use Storyblok\ManagementApi\Data\AppProvisions;
use Tests\TestCase;

final class AppProvisionListResultTest extends TestCase
{
    #[Test]
    public function count_returns_zero_for_empty(): void
    {
        $result = new AppProvisionListResult(provisions: AppProvisions::make([]));

        $this->assertSame(0, $result->count());
    }

    #[Test]
    public function count_returns_number_of_provisions(): void
    {
        $result = new AppProvisionListResult(provisions: AppProvisions::make([
            ['name' => 'Activities', 'app_id' => 14],
        ]));

        $this->assertSame(1, $result->count());
    }
}
