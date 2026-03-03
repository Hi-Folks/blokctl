<?php

declare(strict_types=1);

namespace Blokctl\Action\AppProvision;

use Storyblok\ManagementApi\Data\AppProvisions;

final readonly class AppProvisionListResult
{
    public function __construct(
        public AppProvisions $provisions,
    ) {}

    public function count(): int
    {
        return $this->provisions->count();
    }
}
