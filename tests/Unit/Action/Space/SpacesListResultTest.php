<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Space;

use Blokctl\Action\Space\SpacesListResult;
use PHPUnit\Framework\Attributes\Test;
use Storyblok\ManagementApi\Data\Space;
use Tests\TestCase;

final class SpacesListResultTest extends TestCase
{
    #[Test]
    public function count_returns_zero_for_empty(): void
    {
        $result = new SpacesListResult(spaces: []);

        $this->assertSame(0, $result->count());
    }

    #[Test]
    public function count_returns_number_of_spaces(): void
    {
        $result = new SpacesListResult(spaces: [
            new Space('Space 1'),
            new Space('Space 2'),
            new Space('Space 3'),
        ]);

        $this->assertSame(3, $result->count());
    }

    #[Test]
    public function errors_default_to_empty_array(): void
    {
        $result = new SpacesListResult(spaces: []);

        $this->assertSame([], $result->errors);
    }

    #[Test]
    public function errors_are_stored(): void
    {
        $result = new SpacesListResult(
            spaces: [],
            errors: ['Error checking collaborators for "Test": rate limited'],
        );

        $this->assertCount(1, $result->errors);
    }
}
