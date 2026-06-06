<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

final class SpaceSetupVariableResolutionException extends \RuntimeException
{
    public function __construct(
        public readonly string $path,
        public readonly string $expression,
        string $message,
    ) {
        parent::__construct($path . ': ' . $message . ' "' . $expression . '".');
    }
}
