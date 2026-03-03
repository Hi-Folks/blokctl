<?php

declare(strict_types=1);

namespace Blokctl\Action\SpacePreview;

use Storyblok\ManagementApi\Data\SpaceEnvironments;

final readonly class SpacePreviewListResult
{
    public function __construct(
        public string $defaultDomain,
        public SpaceEnvironments $environments,
    ) {}

    public function hasEnvironments(): bool
    {
        return $this->environments->count() > 0;
    }
}
