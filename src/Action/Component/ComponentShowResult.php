<?php

declare(strict_types=1);

namespace Blokctl\Action\Component;

use Storyblok\ManagementApi\Data\Component;

final readonly class ComponentShowResult
{
    public function __construct(
        public Component $component,
    ) {}
}
