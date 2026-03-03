<?php

declare(strict_types=1);

namespace Blokctl\Action\User;

use Storyblok\ManagementApi\Endpoints\UserApi;
use Storyblok\ManagementApi\ManagementApiClient;

final readonly class UserMeAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    public function execute(): UserMeResult
    {
        $user = (new UserApi($this->client))->me()->data();

        return new UserMeResult(
            user: $user,
        );
    }
}
