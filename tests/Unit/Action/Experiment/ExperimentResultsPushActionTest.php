<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Experiment;

use Blokctl\Action\Experiment\ExperimentResultsPushAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ExperimentResultsPushActionTest extends TestCase
{
    #[Test]
    public function execute_pushes_experiment_results(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('one-experiment-result'),
        );

        $action = new ExperimentResultsPushAction($client);
        $result = $action->execute('222', '987654321', [
            'charts' => [
                [
                    'kind' => 'bar',
                    'title' => 'Conversion Rate',
                    'labels' => ['Control', 'Variant A'],
                    'series' => [
                        [
                            'label' => 'Conversion rate',
                            'data' => [0.12, 0.15],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('123456789', $result->experimentResult->id());
        $this->assertSame('987654321', $result->experimentResult->experimentId());
        $this->assertSame(1, $result->chartCount());
        $this->assertSame('2026-03-15T10:30:00.000Z', $result->experimentResult->pushedAt());
    }

    #[Test]
    public function parse_json_requires_object_payload(): void
    {
        $action = new ExperimentResultsPushAction($this->createMockClient());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Experiment results JSON must be an object with a "charts" array.');

        $action->parseJson('[{"kind":"bar"}]');
    }

    #[Test]
    public function execute_requires_charts_array(): void
    {
        $action = new ExperimentResultsPushAction($this->createMockClient());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Experiment results payload must include a "charts" array.');

        $action->execute('222', '987654321', []);
    }

    #[Test]
    public function execute_requires_chart_kind(): void
    {
        $action = new ExperimentResultsPushAction($this->createMockClient());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Chart at index 0 must include a non-empty "kind".');

        $action->execute('222', '987654321', [
            'charts' => [
                [
                    'title' => 'Conversion Rate',
                ],
            ],
        ]);
    }

    #[Test]
    public function execute_includes_response_body_when_push_fails(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('experiment-result-forbidden', 403),
        );

        $action = new ExperimentResultsPushAction($client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to push experiment results: 403 - Forbidden. Insufficient permissions.');
        $this->expectExceptionMessage('Response body: {');
        $this->expectExceptionMessage('"message": "This token cannot push experiment results."');

        $action->execute('222', '987654321', [
            'charts' => [
                [
                    'kind' => 'bar',
                    'labels' => ['Control', 'Variant A'],
                    'series' => [
                        [
                            'label' => 'Conversion rate',
                            'data' => [0.12, 0.15],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
