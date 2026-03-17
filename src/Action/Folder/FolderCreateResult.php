<?php

declare(strict_types=1);

namespace Blokctl\Action\Folder;

use Storyblok\ManagementApi\Data\Story;

final readonly class FolderCreateResult
{
    public function __construct(
        public Story $folder,
        public int $parentId,
    ) {}
}
