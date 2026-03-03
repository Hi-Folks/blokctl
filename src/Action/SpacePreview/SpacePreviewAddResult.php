<?php

declare(strict_types=1);

namespace Blokctl\Action\SpacePreview;

use Storyblok\ManagementApi\Data\Space;

final readonly class SpacePreviewAddResult
{
    public function __construct(
        public Space $space,
    ) {}
}
