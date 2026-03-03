<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Story;

use Blokctl\Action\Story\StoriesListResult;
use PHPUnit\Framework\Attributes\Test;
use Storyblok\ManagementApi\Data\Stories;
use Tests\TestCase;

final class StoriesListResultTest extends TestCase
{
    #[Test]
    public function count_returns_zero_for_empty(): void
    {
        $result = new StoriesListResult(stories: Stories::make([]));

        $this->assertSame(0, $result->count());
    }

    #[Test]
    public function count_returns_number_of_stories(): void
    {
        $result = new StoriesListResult(stories: Stories::make([
            ['name' => 'Story 1', 'id' => 1],
            ['name' => 'Story 2', 'id' => 2],
        ]));

        $this->assertSame(2, $result->count());
    }
}
