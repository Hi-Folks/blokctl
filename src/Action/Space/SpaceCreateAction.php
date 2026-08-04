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
                'Failed to create space: ' . $this->errorMessage($response->getErrorMessage(), $response->getResponseBody()),
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

    private function errorMessage(string $fallback, string $body): string
    {
        $baseErrors = $this->baseErrors($body);
        if ($baseErrors !== []) {
            return implode(' ', $baseErrors);
        }

        $details = $this->fieldErrors($body);
        if ($details === []) {
            return $fallback;
        }

        return $fallback . ' ' . implode(' ', $details);
    }

    /**
     * @return string[]
     */
    private function baseErrors(string $body): array
    {
        $payload = $this->jsonPayload($body);
        if ($payload === null) {
            return [];
        }

        $base = $payload['base'] ?? null;
        if (!is_array($base)) {
            return [];
        }

        return array_values(array_filter($base, is_string(...)));
    }

    /**
     * @return string[]
     */
    private function fieldErrors(string $body): array
    {
        $payload = $this->jsonPayload($body);
        if ($payload === null) {
            return [];
        }

        $messages = [];
        foreach ($payload as $field => $errors) {
            if (!is_string($field)) {
                continue;
            }

            if (!is_array($errors)) {
                continue;
            }

            foreach ($errors as $error) {
                if (is_string($error) && $error !== '') {
                    $messages[] = $field . ': ' . $error;
                }
            }
        }

        return $messages;
    }

    /**
     * @return array<mixed>|null
     */
    private function jsonPayload(string $body): array|null
    {
        if ($body === '') {
            return null;
        }

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }
}
