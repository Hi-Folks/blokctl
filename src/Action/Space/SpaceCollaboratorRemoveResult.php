<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

final readonly class SpaceCollaboratorRemoveResult
{
    /** Will be removed (preflight). */
    public const string PLANNED = 'planned';

    /** No collaborator with this email. */
    public const string NOT_FOUND = 'not_found';

    public const string REMOVED = 'removed';

    public const string FAILED = 'failed';

    /**
     * @param list<array{email: string, collaboratorId: int|null, status: string, message: string}> $entries
     *        email is empty for a collaborator ID that is not in the list (e.g. a pending invitation)
     */
    public function __construct(
        public array $entries,
    ) {}

    /**
     * @return list<array{email: string, collaboratorId: int|null, status: string, message: string}>
     */
    public function entriesWithStatus(string $status): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn(array $entry): bool => $entry['status'] === $status,
        ));
    }

    public function hasFailures(): bool
    {
        return $this->entriesWithStatus(self::FAILED) !== [];
    }
}
