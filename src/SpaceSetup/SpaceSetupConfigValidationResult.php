<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

final readonly class SpaceSetupConfigValidationResult
{
    /**
     * @param string[] $errors
     */
    public function __construct(
        public array $errors,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
