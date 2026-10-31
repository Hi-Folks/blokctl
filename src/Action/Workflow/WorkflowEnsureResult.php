<?php

declare(strict_types=1);

namespace Blokctl\Action\Workflow;

final readonly class WorkflowEnsureResult
{
    public const string CREATED = 'created';

    public const string UPDATED = 'updated';

    public const string UNCHANGED = 'unchanged';

    /**
     * @param array<string, mixed> $workflow Workflow object as returned by the Management API
     * @param list<string> $changedFields
     */
    public function __construct(
        public string $status,
        public array $workflow,
        public array $changedFields,
    ) {}

    public function changed(): bool
    {
        return $this->status !== self::UNCHANGED;
    }

    public function workflowId(): int
    {
        $id = $this->workflow['id'] ?? null;

        return is_numeric($id) ? (int) $id : 0;
    }
}
