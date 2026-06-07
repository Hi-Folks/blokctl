<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

final class SpaceSetupProvisioningException extends \RuntimeException
{
    public function __construct(
        public readonly SpaceSetupReporter $reporter,
        \Throwable $previous,
    ) {
        parent::__construct($previous->getMessage(), (int) $previous->getCode(), $previous);
    }
}
