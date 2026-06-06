<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

final readonly class SpaceSetupOperationResult
{
    public function __construct(
        public SpaceSetupOperationStatus $status,
        public string $label,
        public string|null $detail = null,
    ) {}
}
