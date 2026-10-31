<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

final readonly class SpaceSetupContentCheckResult
{
    /**
     * @param list<string> $errors
     * @param list<string> $uncheckedComponents
     */
    public function __construct(
        public array $errors,
        public array $uncheckedComponents = [],
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
