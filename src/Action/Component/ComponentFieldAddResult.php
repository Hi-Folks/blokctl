<?php

declare(strict_types=1);

namespace Blokctl\Action\Component;

use Storyblok\ManagementApi\Data\Component;

final readonly class ComponentFieldAddResult
{
    /**
     * @param array<string, mixed> $schema
     */
    public function __construct(
        public Component $component,
        public array $schema,
    ) {}
}
