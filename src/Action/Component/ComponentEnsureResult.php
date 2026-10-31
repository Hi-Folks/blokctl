<?php

declare(strict_types=1);

namespace Blokctl\Action\Component;

final readonly class ComponentEnsureResult
{
    public const string CREATED = 'created';

    public const string UPDATED = 'updated';

    public const string UNCHANGED = 'unchanged';

    /**
     * @param array<string, mixed> $component Component object as returned by the Management API
     * @param list<string> $changedFields
     */
    public function __construct(
        public string $status,
        public array $component,
        public array $changedFields,
    ) {}

    public function changed(): bool
    {
        return $this->status !== self::UNCHANGED;
    }

    public function componentId(): int
    {
        $id = $this->component['id'] ?? null;

        return is_numeric($id) ? (int) $id : 0;
    }
}
