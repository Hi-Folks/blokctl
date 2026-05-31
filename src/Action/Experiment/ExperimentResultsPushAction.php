<?php

declare(strict_types=1);

namespace Blokctl\Action\Experiment;

use Storyblok\ManagementApi\Data\ExperimentResult;
use Storyblok\ManagementApi\Endpoints\ExperimentApi;
use Storyblok\ManagementApi\ManagementApiClient;

final readonly class ExperimentResultsPushAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function execute(
        string $spaceId,
        string|int $experimentId,
        array $payload,
    ): ExperimentResultsPushResult {
        $charts = $this->extractCharts($payload);

        $response = (new ExperimentApi($this->client, $spaceId))
            ->pushResults($experimentId, ExperimentResult::forCharts($charts));

        if (!$response->isOk()) {
            $message = 'Failed to push experiment results: ' . $response->getErrorMessage();
            $responseBody = trim($response->getResponseBody());
            if ($responseBody !== '') {
                $message .= ' | Response body: ' . $responseBody;
            }

            throw new \RuntimeException(
                $message,
            );
        }

        return new ExperimentResultsPushResult(
            experimentResult: $response->data(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function parseJsonFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException('Experiment results file not found: ' . $filePath);
        }

        $json = file_get_contents($filePath);
        if ($json === false) {
            throw new \RuntimeException('Failed to read experiment results file: ' . $filePath);
        }

        return $this->parseJson($json);
    }

    /**
     * @return array<string, mixed>
     */
    public function parseJson(string $json): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $jsonException) {
            throw new \RuntimeException('Invalid JSON: ' . $jsonException->getMessage(), $jsonException->getCode(), $jsonException);
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new \RuntimeException('Experiment results JSON must be an object with a "charts" array.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function extractCharts(array $payload): array
    {
        if (!isset($payload['charts']) || !is_array($payload['charts'])) {
            throw new \RuntimeException('Experiment results payload must include a "charts" array.');
        }

        if ($payload['charts'] === []) {
            throw new \RuntimeException('Experiment results payload must include at least one chart.');
        }

        if (count($payload['charts']) > 20) {
            throw new \RuntimeException('Experiment results payload must not include more than 20 charts.');
        }

        $charts = [];
        foreach ($payload['charts'] as $index => $chart) {
            if (!is_array($chart) || array_is_list($chart)) {
                throw new \RuntimeException('Chart at index ' . $index . ' must be an object.');
            }

            if (!isset($chart['kind']) || !is_string($chart['kind']) || $chart['kind'] === '') {
                throw new \RuntimeException('Chart at index ' . $index . ' must include a non-empty "kind".');
            }

            /** @var array<string, mixed> $chart */
            $charts[] = $chart;
        }

        return $charts;
    }
}
