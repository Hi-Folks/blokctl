<?php

declare(strict_types=1);

namespace Blokctl\Action\Workflow;

final readonly class WorkflowsListResult
{
    /**
     * @param array<int, array{id: int, name: string, isDefault: bool, stages: array<int, array{id: int, name: string, position: int}>}> $workflows
     */
    public function __construct(
        public array $workflows,
    ) {}

    public function count(): int
    {
        return count($this->workflows);
    }
}
