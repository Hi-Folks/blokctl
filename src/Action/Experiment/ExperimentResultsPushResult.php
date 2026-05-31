<?php

declare(strict_types=1);

namespace Blokctl\Action\Experiment;

use Storyblok\ManagementApi\Data\ExperimentResult;

final readonly class ExperimentResultsPushResult
{
    public function __construct(
        public ExperimentResult $experimentResult,
    ) {}

    public function chartCount(): int
    {
        return count($this->experimentResult->charts());
    }
}
