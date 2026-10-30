<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

use Storyblok\ManagementApi\Endpoints\CollaboratorApi;
use Storyblok\ManagementApi\Endpoints\EndpointBase;
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
        $space = new SpaceApi($this->client)->get($spaceId)->data();
        $user = new UserApi($this->client)->me()->data();
        $collaborators = new CollaboratorApi($this->client, $spaceId)
            ->page()->data();

        return new SpaceDeleteResult(
            space: $space,
            user: $user,
            collaborators: $collaborators,
            isOwner: $space->isOwnedByUser($user),
            isSolo: $collaborators->count() <= 1,
            latestActivities: $this->latestActivities($spaceId),
        );
    }

    /**
     * Delete the space. Call only after preflight confirms canDelete().
     *
     * @throws \RuntimeException if the current user is not the owner
     */
    public function execute(string $spaceId, SpaceDeleteResult $preflight): void
    {
        if (!$preflight->canDelete()) {
            throw new \RuntimeException('Cannot delete space: you are not the owner');
        }

        new SpaceApi($this->client)->delete($spaceId);
    }

    /**
     * @return list<SpaceDeleteActivity>
     */
    private function latestActivities(string $spaceId): array
    {
        try {
            $payload = new EndpointBase($this->client)
                ->makeRequest(
                    'GET',
                    sprintf('/v1/spaces/%s/activities/', rawurlencode($spaceId)),
                    [
                        'query' => [
                            'page' => 1,
                            'per_page' => 3,
                        ],
                    ],
                )
                ->toArray();
        } catch (\Throwable) {
            return [];
        }

        $activities = $payload['activities'] ?? [];
        if (!is_array($activities)) {
            return [];
        }

        $users = $this->indexedPayloadObjects($payload['users'] ?? []);
        $trackables = $this->indexedPayloadObjects($payload['trackables'] ?? []);

        return array_values(
            array_filter(
                array_map(
                    function (mixed $activity) use (
                        $trackables,
                        $users,
                    ): ?SpaceDeleteActivity {
                        if (!is_array($activity)) {
                            return null;
                        }

                        $activity = $this->activityPayload($activity);

                        $ownerId = $activity['owner_id'] ?? null;
                        $user = is_scalar($ownerId)
                            ? ($users[(string) $ownerId] ?? null)
                            : null;

                        $trackableId = $activity['trackable_id'] ?? null;
                        $trackable = is_scalar($trackableId)
                            ? ($trackables[(string) $trackableId] ?? null)
                            : null;

                        return new SpaceDeleteActivity(
                            $activity,
                            $user,
                            $trackable,
                        );
                    },
                    array_slice($activities, 0, 3),
                ),
            ),
        );
    }

    /**
     * @param array<mixed> $activity
     * @return array<string, mixed>
     */
    private function activityPayload(array $activity): array
    {
        $payload = $activity['activity'] ?? $activity;

        if (!is_array($payload)) {
            return [];
        }

        return $this->stringKeyedArray($payload);
    }

    /**
     * @param array<mixed> $payload
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $payload): array
    {
        $result = [];

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function indexedPayloadObjects(mixed $objects): array
    {
        if (!is_array($objects)) {
            return [];
        }

        $indexed = [];

        foreach ($objects as $object) {
            if (!is_array($object)) {
                continue;
            }

            $id = $object['id'] ?? $object['userid'] ?? null;
            if (is_scalar($id)) {
                $indexed[(string) $id] = $object;
            }
        }

        return $indexed;
    }
}
