<?php

declare(strict_types=1);

namespace Blokctl\Action\Experiment;

use Storyblok\ManagementApi\Data\Experiment;

final readonly class ExperimentCreateResult
{
    public function __construct(
        public Experiment $experiment,
    ) {}
}
