<?php

declare(strict_types=1);

namespace Blokctl\Action\AppProvision;

use Storyblok\ManagementApi\Data\AppProvision;
use Storyblok\ManagementApi\Endpoints\AppApi;
use Storyblok\ManagementApi\Endpoints\AppProvisionApi;
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\QueryParameters\AppsParams;

final readonly class AppProvisionInstallAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * Resolve an app ID from a slug.
     *
     * @throws \RuntimeException if the slug is not found
     */
    public function resolveBySlug(string $spaceId, string $slug): string
    {
        $apps = (new AppApi($this->client))
            ->page(new AppsParams($spaceId, 1, 100))
            ->data();

        /** @var \Storyblok\ManagementApi\Data\App $app */
        foreach ($apps as $app) {
            if ($app->slug() === $slug) {
                return $app->id();
            }
        }

        throw new \RuntimeException('No app found with slug: ' . $slug);
    }

    /**
     * Fetch available apps for interactive selection.
     */
    public function preflight(string $spaceId): AppProvisionInstallResult
    {
        $apps = (new AppApi($this->client))
            ->page(new AppsParams($spaceId))
            ->data();

        $appOptions = [];
        /** @var \Storyblok\ManagementApi\Data\App $app */
        foreach ($apps as $app) {
            $appOptions[$app->id()] = $app->name() . ' (' . $app->slug() . ')';
        }

        return new AppProvisionInstallResult(
            appOptions: $appOptions,
        );
    }

    /**
     * Install an app by ID.
     */
    public function execute(string $spaceId, string $appId): AppProvision
    {
        return (new AppProvisionApi($this->client, $spaceId))
            ->install($appId)->data();
    }
}
