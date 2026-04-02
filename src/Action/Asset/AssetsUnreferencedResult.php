<?php

declare(strict_types=1);

namespace Blokctl\Action\Asset;

use Storyblok\ManagementApi\Data\Asset;

final readonly class AssetsUnreferencedResult
{
    /**
     * @param array<int, Asset> $unreferencedAssets
     */
    public function __construct(
        public array $unreferencedAssets,
        public int $totalAssets,
        public int $referencedCount,
        public int $storiesAnalyzed,
    ) {}

    public function unreferencedCount(): int
    {
        return count($this->unreferencedAssets);
    }
}
