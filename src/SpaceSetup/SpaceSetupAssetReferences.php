<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

use Storyblok\ManagementApi\Data\Asset;

/**
 * Assets uploaded by assets.upload_url during one setup run, by reference name, so later
 * steps can use them with `asset: { _ref: name }`.
 */
final class SpaceSetupAssetReferences
{
    /** @var array<string, Asset> */
    private array $assets = [];

    public function set(string $name, Asset $asset): void
    {
        $this->assets[$name] = $asset;
    }

    public function get(string $name): ?Asset
    {
        return $this->assets[$name] ?? null;
    }

    public function clear(): void
    {
        $this->assets = [];
    }
}
