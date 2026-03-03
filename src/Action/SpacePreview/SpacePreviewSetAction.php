<?php

declare(strict_types=1);

namespace Blokctl\Action\SpacePreview;

use Storyblok\ManagementApi\Data\Space;
use Storyblok\ManagementApi\Data\SpaceEnvironment;
use Storyblok\ManagementApi\Endpoints\SpaceApi;
use Storyblok\ManagementApi\ManagementApiClient;

final readonly class SpacePreviewSetAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    public function preflight(string $spaceId): SpacePreviewSetResult
    {
        $space = (new SpaceApi($this->client))->get($spaceId)->data();

        return new SpacePreviewSetResult(
            space: $space,
        );
    }

    /**
     * Set the default preview URL and optional environments.
     *
     * @param array<SpaceEnvironment> $environments
     */
    public function execute(
        string $spaceId,
        SpacePreviewSetResult $preflight,
        string $previewUrl,
        array $environments = [],
    ): void {
        $editSpace = new Space($preflight->space->name());
        $editSpace->set('id', $preflight->space->id());
        $editSpace->setDomain($previewUrl);

        foreach ($environments as $environment) {
            $editSpace->addEnvironment($environment);
        }

        (new SpaceApi($this->client))->update($spaceId, $editSpace);
    }
}
