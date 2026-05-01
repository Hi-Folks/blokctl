<?php

declare(strict_types=1);

namespace Blokctl\Action\Folder;

use Storyblok\ManagementApi\Data\Story;

final readonly class FolderDimensionAddResult
{
    /**
     * @param int[] $folderIds
     * @param array<int, array{folder_id: int, ai_translation_code: string}> $dimensionsFolders
     */
    public function __construct(
        public Story $folder,
        public array $folderIds,
        public array $dimensionsFolders,
    ) {}
}
