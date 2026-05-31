<?php

declare(strict_types=1);

namespace Blokctl\Action\Experiment;

use Storyblok\ManagementApi\Endpoints\ExperimentApi;
use Storyblok\ManagementApi\ManagementApiClient;

final readonly class ExperimentsListAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    public function execute(
        string $spaceId,
        int $page = 1,
        int $perPage = 25,
    ): ExperimentsListResult {
        $response = (new ExperimentApi($this->client, $spaceId))
            ->page($page, $perPage);

        if (!$response->isOk()) {
            throw new \RuntimeException(
                'Failed to list experiments: ' . $response->getErrorMessage(),
            );
        }

        return new ExperimentsListResult(
            experiments: $response->data(),
            total: $response->total(),
            perPage: $response->perPage(),
        );
    }
}
