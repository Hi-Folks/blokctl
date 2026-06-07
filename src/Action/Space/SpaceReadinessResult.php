<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

final readonly class SpaceReadinessResult
{
    public function __construct(
        public int $attempts,
        public float $elapsedSeconds,
    ) {}
}
