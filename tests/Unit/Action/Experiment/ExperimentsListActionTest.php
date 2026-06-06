<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Experiment;

use Blokctl\Action\Experiment\ExperimentsListAction;
use PHPUnit\Framework\Attributes\Test;
use Storyblok\ManagementApi\Data\Experiment;
use Tests\TestCase;

final class ExperimentsListActionTest extends TestCase
{
    #[Test]
    public function execute_lists_experiments(): void
    {
        $client = $this->createMockClient(
            $this->mockResponse('list-experiments', 200, [
                'total' => 2,
                'per-page' => 25,
            ]),
        );

        $result = new ExperimentsListAction($client)->execute(
            spaceId: '222',
            page: 1,
            perPage: 25,
        );

        $this->assertSame(2, $result->count());
        $this->assertSame(2, $result->total);
        $this->assertSame(25, $result->perPage);

        $firstExperiment = $result->experiments[0];
        $secondExperiment = $result->experiments[1];

        $this->assertInstanceOf(Experiment::class, $firstExperiment);
        $this->assertInstanceOf(Experiment::class, $secondExperiment);
        $this->assertSame('Homepage Hero Test', $firstExperiment->displayName());
        $this->assertSame('running', $secondExperiment->status());
    }
}
