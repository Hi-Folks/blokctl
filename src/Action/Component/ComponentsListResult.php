<?php

declare(strict_types=1);

namespace Blokctl\Action\Component;

use Storyblok\ManagementApi\Data\Components;

final readonly class ComponentsListResult
{
    public function __construct(
        public Components $components,
    ) {}

    public function count(): int
    {
        return $this->components->count();
    }
}
