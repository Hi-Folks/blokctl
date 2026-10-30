<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

final readonly class SpaceCollaboratorInviteResult
{
    /** Not sent yet (preflight). */
    public const string PLANNED = 'planned';

    /** Already a collaborator of the space. */
    public const string SKIPPED = 'skipped';

    /** Existing Storyblok user added to the space. */
    public const string ADDED = 'added';

    /** Pending invitation created for an email without a Storyblok account. */
    public const string INVITED = 'invited';

    public const string FAILED = 'failed';

    /**
     * @param string $roleName "admin", "editor", or the custom space role name
     * @param int|null $spaceRoleId Custom space role ID, null for built-in roles
     * @param list<array{email: string, status: string, message: string}> $entries
     */
    public function __construct(
        public string $roleName,
        public ?int $spaceRoleId,
        public array $entries,
    ) {}

    /**
     * @return list<string>
     */
    public function emailsWithStatus(string $status): array
    {
        $emails = [];
        foreach ($this->entries as $entry) {
            if ($entry['status'] === $status) {
                $emails[] = $entry['email'];
            }
        }

        return $emails;
    }

    public function hasFailures(): bool
    {
        return $this->emailsWithStatus(self::FAILED) !== [];
    }
}
