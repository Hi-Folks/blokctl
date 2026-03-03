<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

use Storyblok\ManagementApi\Data\Space;
use Storyblok\ManagementApi\Endpoints\CollaboratorApi;
use Storyblok\ManagementApi\Endpoints\SpaceApi;
use Storyblok\ManagementApi\Endpoints\UserApi;
use Storyblok\ManagementApi\ManagementApiClient;
use Storyblok\ManagementApi\QueryParameters\SpacesParams;

final readonly class SpacesListAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    public function execute(
        ?string $search = null,
        bool $ownedOnly = false,
        ?int $updatedBeforeDays = null,
        bool $soloOnly = false,
    ): SpacesListResult {
        $params = $search ? new SpacesParams(search: $search) : null;
        $allSpaces = (new SpaceApi($this->client))->all($params)->data();

        if ($soloOnly) {
            $ownedOnly = true;
        }

        $user = null;
        if ($ownedOnly) {
            $user = (new UserApi($this->client))->me()->data();
        }

        $cutoffDate = null;
        if ($updatedBeforeDays !== null) {
            $cutoffDate = new \DateTimeImmutable(sprintf('-%d days', $updatedBeforeDays));
        }

        /** @var Space[] $filtered */
        $filtered = [];
        $errors = [];

        /** @var Space $space */
        foreach ($allSpaces as $space) {
            if ($ownedOnly && !$space->isOwnedByUser($user)) {
                continue;
            }

            if ($cutoffDate instanceof \DateTimeImmutable) {
                $updatedAt = $space->updatedAt();
                if ($updatedAt === null) {
                    continue;
                }

                if ($updatedAt === '') {
                    continue;
                }

                $spaceDate = new \DateTimeImmutable($updatedAt);
                if ($spaceDate >= $cutoffDate) {
                    continue;
                }
            }

            if ($soloOnly) {
                try {
                    $collaboratorApi = new CollaboratorApi(
                        $this->client,
                        $space->id(),
                    );
                    $collaborators = $collaboratorApi->page()->data();
                    if ($collaborators->count() > 0) {
                        continue;
                    }
                } catch (\Exception $e) {
                    $errors[] = 'Error checking collaborators for "'
                        . $space->name() . '": ' . $e->getMessage();
                    sleep(1);
                    continue;
                }
            }

            $filtered[] = $space;
        }

        return new SpacesListResult(
            spaces: $filtered,
            errors: $errors,
        );
    }
}
