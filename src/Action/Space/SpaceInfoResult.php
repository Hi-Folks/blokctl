<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

use Storyblok\ManagementApi\Data\Space;
use Storyblok\ManagementApi\Data\User;

final readonly class SpaceInfoResult
{
    public function __construct(
        public Space $space,
        public User $user,
        public bool $isOwner,
    ) {}
}
