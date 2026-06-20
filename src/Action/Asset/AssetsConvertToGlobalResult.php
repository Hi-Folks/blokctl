<?php

declare(strict_types=1);

namespace Blokctl\Action\Asset;

final readonly class AssetsConvertToGlobalResult
{
    /**
     * @param list<int> $assetIds
     * @param list<int> $convertedAssetIds
     * @param list<string> $errors
     */
    public function __construct(
        public array $assetIds,
        public array $convertedAssetIds,
        public array $errors = [],
        public bool $dryRun = false,
    ) {}

    public function total(): int
    {
        return count($this->assetIds);
    }

    public function converted(): int
    {
        return count($this->convertedAssetIds);
    }

    public function failed(): int
    {
        return count($this->errors);
    }
}
