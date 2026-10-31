<?php

declare(strict_types=1);

namespace Blokctl\Action\Workflow;

final readonly class WorkflowStageEnsureResult
{
    public const string CREATED = 'created';

    public const string UPDATED = 'updated';

    public const string UNCHANGED = 'unchanged';

    /**
     * @param array<string, mixed> $stage Workflow stage object as returned by the Management API
     * @param list<string> $changedFields API property names that were sent
     */
    public function __construct(
        public string $status,
        public array $stage,
        public array $changedFields,
    ) {}

    public function changed(): bool
    {
        return $this->status !== self::UNCHANGED;
    }

    public function stageId(): int
    {
        $id = $this->stage['id'] ?? null;

        return is_numeric($id) ? (int) $id : 0;
    }
}
