<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

final readonly class SpaceSetupInputsResult
{
    /**
     * @param array<string, mixed> $values
     * @param list<mixed> $secretValues Includes resolved values and secret defaults.
     */
    public function __construct(
        public array $values,
        public array $secretValues,
    ) {}
}
