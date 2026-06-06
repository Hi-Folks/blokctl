<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

enum SpaceSetupOperationStatus: string
{
    case Planned = 'PLANNED';
    case Updated = 'UPDATED';
    case Installed = 'INSTALLED';
    case Created = 'CREATED';
    case Removed = 'REMOVED';
    case Skipped = 'SKIPPED';
    case Failed = 'FAILED';

    public function color(): string
    {
        return match ($this) {
            self::Planned, self::Skipped => 'yellow',
            self::Failed => 'red',
            default => 'green',
        };
    }
}
