<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

use Storyblok\ManagementApi\Data\Space;
use Storyblok\ManagementApi\Endpoints\ManagementApi;
use Storyblok\ManagementApi\ManagementApiClient;

final readonly class SpaceCreateAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    public function execute(
        string $name,
        string|null $duplicateFrom = null,
        bool $isDemo = false,
        bool $inOrg = false,
    ): SpaceCreateResult {
        $spacePayload = [
            'name' => $name,
        ];

        if ($isDemo) {
            $spacePayload['is_demo'] = true;
        }

        $payload = [
            'space' => $spacePayload,
        ];

        if ($duplicateFrom !== null && $duplicateFrom !== '') {
            $payload['dup_id'] = $duplicateFrom;
            if ($inOrg) {
                $payload['in_org'] = true;
            }
        }

        $response = new ManagementApi($this->client)->post('spaces', $payload);
        if (!$response->isOk()) {
            throw new \RuntimeException(
                'Failed to create space: ' . $response->getErrorMessage(),
            );
        }

        $data = $response->toArray();
        if (!isset($data['space']) || !is_array($data['space'])) {
            throw new \RuntimeException('Failed to create space: response does not contain a space object.');
        }

        return new SpaceCreateResult(
            space: Space::make($data['space']),
            duplicated: $duplicateFrom !== null && $duplicateFrom !== '',
            duplicateFrom: $duplicateFrom,
        );
    }
}
