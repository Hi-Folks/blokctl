<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

use Storyblok\ManagementApi\Endpoints\CollaboratorApi;
use Storyblok\ManagementApi\Endpoints\SpaceApi;
use Storyblok\ManagementApi\Endpoints\UserApi;
use Storyblok\ManagementApi\ManagementApiClient;

final readonly class SpaceDeleteAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * Preflight: fetch space, user, collaborators and evaluate safety checks.
     */
    public function preflight(string $spaceId): SpaceDeleteResult
    {
        $space = (new SpaceApi($this->client))->get($spaceId)->data();
        $user = (new UserApi($this->client))->me()->data();
        $collaborators = (new CollaboratorApi($this->client, $spaceId))
            ->page()->data();

        return new SpaceDeleteResult(
            space: $space,
            user: $user,
            collaborators: $collaborators,
            isOwner: $space->isOwnedByUser($user),
            isSolo: $collaborators->count() <= 1,
        );
    }

    /**
     * Delete the space. Call only after preflight confirms canDelete().
     *
     * @throws \RuntimeException if safety checks fail
     */
    public function execute(string $spaceId, SpaceDeleteResult $preflight): void
    {
        if (!$preflight->canDelete()) {
            throw new \RuntimeException(
                'Cannot delete space: '
                . ($preflight->isOwner ? 'other collaborators exist' : 'you are not the owner'),
            );
        }

        (new SpaceApi($this->client))->delete($spaceId);
    }
}
