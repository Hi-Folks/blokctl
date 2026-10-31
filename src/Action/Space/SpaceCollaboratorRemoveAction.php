<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

use Blokctl\ApiError;
use Storyblok\ManagementApi\Endpoints\ManagementApi;
use Storyblok\ManagementApi\ManagementApiClient;

/**
 * Remove collaborators from a space, by email or collaborator ID.
 *
 * Pending invitations are not returned by the collaborators list, so they can only
 * be removed by collaborator ID (returned when the invitation was created).
 */
final readonly class SpaceCollaboratorRemoveAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * Match emails and IDs against the current collaborators, without changing the space.
     *
     * Emails that don't match are NOT_FOUND. IDs that don't match are still PLANNED,
     * because they may belong to a pending invitation (the email is then unknown).
     *
     * @param list<string> $emails
     * @param list<string|int> $collaboratorIds
     *
     * @throws \RuntimeException if no reference is given or the collaborators cannot be fetched
     */
    public function preflight(string $spaceId, array $emails = [], array $collaboratorIds = []): SpaceCollaboratorRemoveResult
    {
        if ($emails === [] && $collaboratorIds === []) {
            throw new \RuntimeException('At least one email or collaborator ID is required.');
        }

        $collaborators = new SpaceCollaboratorListAction($this->client)->execute($spaceId);
        $entries = [];

        foreach ($emails as $email) {
            $email = strtolower(trim($email));
            $id = $collaborators->findByEmail($email)['id'] ?? null;
            $entries[$email] = is_numeric($id)
                ? $this->entry($email, (int) $id, SpaceCollaboratorRemoveResult::PLANNED)
                : $this->entry($email, null, SpaceCollaboratorRemoveResult::NOT_FOUND);
        }

        foreach ($collaboratorIds as $collaboratorId) {
            $collaboratorId = trim((string) $collaboratorId);
            if (!ctype_digit($collaboratorId)) {
                throw new \RuntimeException('Invalid collaborator ID: ' . $collaboratorId);
            }

            $email = '';
            foreach ($collaborators->collaborators as $collaborator) {
                $id = $collaborator['id'] ?? null;
                if (is_scalar($id) && (string) $id === $collaboratorId) {
                    $email = SpaceCollaboratorListResult::emailOf($collaborator);
                }
            }

            $key = $email !== '' ? $email : '#' . $collaboratorId;
            $entries[$key] = $this->entry($email, (int) $collaboratorId, SpaceCollaboratorRemoveResult::PLANNED);
        }

        return new SpaceCollaboratorRemoveResult(array_values($entries));
    }

    /**
     * Remove every planned collaborator from the preflight. Failures are collected per entry.
     */
    public function execute(string $spaceId, SpaceCollaboratorRemoveResult $preflight): SpaceCollaboratorRemoveResult
    {
        $api = new ManagementApi($this->client);
        $entries = [];
        foreach ($preflight->entries as $entry) {
            if ($entry['status'] !== SpaceCollaboratorRemoveResult::PLANNED || $entry['collaboratorId'] === null) {
                $entries[] = $entry;
                continue;
            }

            $response = $api->delete(sprintf('spaces/%s/collaborators/%d', $spaceId, $entry['collaboratorId']));
            $entries[] = $response->isOk()
                ? [...$entry, 'status' => SpaceCollaboratorRemoveResult::REMOVED]
                : [...$entry, 'status' => SpaceCollaboratorRemoveResult::FAILED, 'message' => ApiError::message($response)];
        }

        return new SpaceCollaboratorRemoveResult($entries);
    }

    /**
     * @return array{email: string, collaboratorId: int|null, status: string, message: string}
     */
    private function entry(string $email, ?int $collaboratorId, string $status): array
    {
        return ['email' => $email, 'collaboratorId' => $collaboratorId, 'status' => $status, 'message' => ''];
    }
}
