<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

use Storyblok\ManagementApi\Data\Space;

final readonly class SpacesListResult
{
    /**
     * @param Space[] $spaces
     * @param string[] $errors
     */
    public function __construct(
        public array $spaces,
        public array $errors = [],
    ) {}

    public function count(): int
    {
        return count($this->spaces);
    }
}
