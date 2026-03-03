<?php

declare(strict_types=1);

namespace Blokctl\Action\User;

use Storyblok\ManagementApi\Data\User;

final readonly class UserMeResult
{
    public function __construct(
        public User $user,
    ) {}
}
