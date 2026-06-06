<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

final class SpaceSetupValueMasker
{
    public function mask(string $value): string
    {
        return (string) preg_replace(
            '/([?&]token=)[^&\s]+/i',
            '$1********',
            $value,
        );
    }
}
