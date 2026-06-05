<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

use Storyblok\ManagementApi\Data\Space;

final readonly class SpaceCreateResult
{
    public function __construct(
        public Space $space,
        public bool $duplicated,
        public string|null $duplicateFrom,
    ) {}
}
