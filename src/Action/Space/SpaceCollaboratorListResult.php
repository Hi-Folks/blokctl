<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

final readonly class SpaceCollaboratorListResult
{
    /**
     * @param list<array<string, mixed>> $collaborators Collaborator objects as returned by the Management API
     */
    public function __construct(
        public array $collaborators,
    ) {}

    public function count(): int
    {
        return count($this->collaborators);
    }

    /**
     * Find a collaborator by email (case-insensitive).
     *
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $email = strtolower(trim($email));
        foreach ($this->collaborators as $collaborator) {
            if (self::emailOf($collaborator) === $email) {
                return $collaborator;
            }
        }

        return null;
    }

    /**
     * Lowercase email of a collaborator: the user's real email, or the invitation email
     * for a pending invitation. Empty string when none is available.
     *
     * @param array<mixed> $collaborator
     */
    public static function emailOf(array $collaborator): string
    {
        $user = is_array($collaborator['user'] ?? null) ? $collaborator['user'] : [];
        $invitation = is_array($collaborator['invitation'] ?? null) ? $collaborator['invitation'] : [];
        $email = $user['real_email'] ?? $invitation['email'] ?? '';

        return is_string($email) ? strtolower($email) : '';
    }

    /**
     * Whether the collaborator is a pending invitation (no Storyblok user yet).
     *
     * @param array<mixed> $collaborator
     */
    public static function isPending(array $collaborator): bool
    {
        return !is_array($collaborator['user'] ?? null) && is_array($collaborator['invitation'] ?? null);
    }
}
