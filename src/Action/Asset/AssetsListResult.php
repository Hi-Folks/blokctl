<?php

declare(strict_types=1);

namespace Blokctl\Action\Asset;

use Storyblok\ManagementApi\Data\Assets;

final readonly class AssetsListResult
{
    public function __construct(
        public Assets $assets,
    ) {}

    public function count(): int
    {
        return $this->assets->count();
    }
}
