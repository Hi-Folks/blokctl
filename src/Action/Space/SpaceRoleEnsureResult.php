<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

final readonly class SpaceRoleEnsureResult
{
    public const string CREATED = 'created';

    public const string UPDATED = 'updated';

    public const string UNCHANGED = 'unchanged';

    /**
     * @param string $status One of CREATED, UPDATED, UNCHANGED
     * @param array<string, mixed> $role The space role object after the operation
     * @param list<string> $changedFields Declared fields that were set on create or changed on update
     */
    public function __construct(
        public string $status,
        public array $role,
        public array $changedFields,
    ) {}

    public function changed(): bool
    {
        return $this->status !== self::UNCHANGED;
    }

    public function roleId(): int
    {
        $id = $this->role['id'] ?? null;

        return is_numeric($id) ? (int) $id : 0;
    }
}
