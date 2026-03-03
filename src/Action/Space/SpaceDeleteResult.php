<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

use Storyblok\ManagementApi\Data\Collaborators;
use Storyblok\ManagementApi\Data\Space;
use Storyblok\ManagementApi\Data\User;

final readonly class SpaceDeleteResult
{
    public function __construct(
        public Space $space,
        public User $user,
        public Collaborators $collaborators,
        public bool $isOwner,
        public bool $isSolo,
    ) {}

    public function canDelete(): bool
    {
        return $this->isOwner && $this->isSolo;
    }
}
