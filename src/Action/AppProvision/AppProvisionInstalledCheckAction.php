<?php

declare(strict_types=1);

namespace Blokctl\Action\AppProvision;

use Storyblok\ManagementApi\Data\AppProvision;
use Storyblok\ManagementApi\Endpoints\AppProvisionApi;
use Storyblok\ManagementApi\ManagementApiClient;

final readonly class AppProvisionInstalledCheckAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    public function isInstalled(string $spaceId, string $slug): bool
    {
        $provisions = new AppProvisionApi($this->client, $spaceId)->page()->data();
        foreach ($provisions as $provision) {
            if ($provision instanceof AppProvision && $provision->slug() === $slug) {
                return true;
            }
        }

        return false;
    }

    public function requireInstalled(string $spaceId, string $slug): void
    {
        if ($this->isInstalled($spaceId, $slug)) {
            return;
        }

        throw new \RuntimeException('Required app is not installed: ' . $slug);
    }
}
