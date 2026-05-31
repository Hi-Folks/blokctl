<?php

declare(strict_types=1);

namespace Blokctl\Action\Experiment;

use Storyblok\ManagementApi\Data\Experiments;

final readonly class ExperimentsListResult
{
    public function __construct(
        public Experiments $experiments,
        public int|null $total,
        public int|null $perPage,
    ) {}

    public function count(): int
    {
        return $this->experiments->howManyExperiments();
    }
}
