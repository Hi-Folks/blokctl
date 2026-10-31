<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

use Blokctl\ApiError;
use Storyblok\ManagementApi\Endpoints\ManagementApi;
use Storyblok\ManagementApi\ManagementApiClient;

final readonly class SpaceCollaboratorListAction
{
    private const int PER_PAGE = 100;

    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * List all collaborators of the space (every page).
     *
     * Pending invitations (users without a Storyblok account yet) are not
     * returned by the Management API collaborators list.
     *
     * @throws \RuntimeException if the collaborators cannot be fetched
     */
    public function execute(string $spaceId): SpaceCollaboratorListResult
    {
        $api = new ManagementApi($this->client);
        $collaborators = [];
        $page = 1;
        do {
            $response = $api->get(
                sprintf('spaces/%s/collaborators', $spaceId),
                ['page' => $page, 'per_page' => self::PER_PAGE],
            );

            if (!$response->isOk()) {
                throw new \RuntimeException('Failed to fetch collaborators: ' . ApiError::message($response));
            }

            $data = $response->toArray();
            $rawCollaborators = is_array($data['collaborators'] ?? null) ? $data['collaborators'] : [];
            foreach ($rawCollaborators as $collaborator) {
                if (is_array($collaborator)) {
                    /** @var array<string, mixed> $collaborator */
                    $collaborators[] = $collaborator;
                }
            }

            $page++;
        } while (count($rawCollaborators) === self::PER_PAGE);

        return new SpaceCollaboratorListResult(collaborators: $collaborators);
    }
}
