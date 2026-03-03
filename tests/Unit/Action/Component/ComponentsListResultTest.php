<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Component;

use Blokctl\Action\Component\ComponentsListResult;
use PHPUnit\Framework\Attributes\Test;
use Storyblok\ManagementApi\Data\Components;
use Tests\TestCase;

final class ComponentsListResultTest extends TestCase
{
    #[Test]
    public function count_returns_zero_for_empty(): void
    {
        $result = new ComponentsListResult(components: Components::make([]));

        $this->assertSame(0, $result->count());
    }

    #[Test]
    public function count_returns_number_of_components(): void
    {
        $result = new ComponentsListResult(components: Components::make([
            ['name' => 'page', 'id' => 1],
            ['name' => 'teaser', 'id' => 2],
        ]));

        $this->assertSame(2, $result->count());
    }
}
