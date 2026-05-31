<?php

declare(strict_types=1);

namespace Blokctl\Action\Experiment;

use Storyblok\ManagementApi\Data\Experiment;
use Storyblok\ManagementApi\Endpoints\ExperimentApi;
use Storyblok\ManagementApi\ManagementApiClient;

final readonly class ExperimentCreateAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * @param int[] $storyIds
     */
    public function execute(
        string $spaceId,
        string $name,
        string $displayName,
        string $description,
        array $storyIds,
    ): ExperimentCreateResult {
        $experiment = Experiment::make()
            ->setName($name)
            ->setDisplayName($displayName)
            ->setDescription($description)
            ->setExperimentVariantsAttributes([
                [
                    'display_name' => 'Control',
                    'is_control' => true,
                    'name' => 'control',
                    'weight' => 50,
                ],
                [
                    'display_name' => 'Variant A',
                    'is_control' => false,
                    'name' => 'variant_a',
                    'weight' => 50,
                ],
            ]);

        if ($storyIds !== []) {
            $experiment->setStoryIds($storyIds);
        }

        $response = (new ExperimentApi($this->client, $spaceId))
            ->create($experiment);

        if (!$response->isOk()) {
            $message = 'Failed to create experiment: ' . $response->getErrorMessage();
            $responseBody = trim($response->getResponseBody());
            if ($responseBody !== '') {
                $message .= ' | Response body: ' . $responseBody;
            }

            throw new \RuntimeException($message);
        }

        return new ExperimentCreateResult(
            experiment: $response->data(),
        );
    }
}
