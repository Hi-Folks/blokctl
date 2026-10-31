<?php

declare(strict_types=1);

namespace Blokctl\Command;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'stories:unpublish',
    description: 'Unpublish selected stories (by full slug, ID, or folder)',
)]
class StoriesUnpublishCommand extends StoriesPublishCommand
{
    #[\Override]
    protected function publish(): bool
    {
        return false;
    }
}
