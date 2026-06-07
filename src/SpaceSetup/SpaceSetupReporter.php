<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

use Blokctl\Render;

final class SpaceSetupReporter
{
    /**
     * @var SpaceSetupOperationResult[]
     */
    private array $results = [];

    public function __construct(
        private readonly bool $dryRun,
    ) {}

    public function start(string $spaceId, string $mode): void
    {
        Render::title($this->dryRun ? 'SPACE SETUP PLAN' : 'SPACE SETUP');
        Render::titleSection('Target');
        Render::labelValue('Mode', $mode);
        Render::labelValue('Space ID', $spaceId);
        Render::titleSection('Operations');
    }

    /**
     * @param \Closure(): (SpaceSetupOperationResult|null) $operation
     */
    public function run(
        string $label,
        SpaceSetupOperationStatus $successStatus,
        bool $continueOnError,
        \Closure $operation,
    ): void {
        try {
            $result = $operation();
            if ($this->dryRun) {
                $result = new SpaceSetupOperationResult(
                    SpaceSetupOperationStatus::Planned,
                    $label,
                    $result?->detail,
                );
            } elseif ($result === null) {
                $result = new SpaceSetupOperationResult($successStatus, $label);
            }

            $this->record($result);
        } catch (\Exception $exception) {
            $this->record(new SpaceSetupOperationResult(
                SpaceSetupOperationStatus::Failed,
                $label,
                $exception->getMessage(),
            ));

            if (!$continueOnError) {
                throw $exception;
            }
        }
    }

    public function finish(): void
    {
        $counts = [];
        foreach ($this->results as $result) {
            $counts[$result->status->value] = ($counts[$result->status->value] ?? 0) + 1;
        }

        Render::titleSection('Summary');
        foreach (SpaceSetupOperationStatus::cases() as $status) {
            $count = $counts[$status->value] ?? 0;
            if ($count > 0) {
                Render::labelValue(ucfirst(strtolower($status->value)), (string) $count);
            }
        }

        Render::notice(
            $this->dryRun
                ? 'DRY RUN: No changes were applied.'
                : 'Space setup complete.',
        );
    }

    /**
     * @return SpaceSetupOperationResult[]
     */
    public function results(): array
    {
        return $this->results;
    }

    public function hasFailures(): bool
    {
        return array_any($this->results, fn($result): bool => $result->status === SpaceSetupOperationStatus::Failed);
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];
        foreach (SpaceSetupOperationStatus::cases() as $status) {
            $counts[strtolower($status->value)] = 0;
        }

        foreach ($this->results as $result) {
            ++$counts[strtolower($result->status->value)];
        }

        return $counts;
    }

    private function record(SpaceSetupOperationResult $result): void
    {
        $this->results[] = $result;
        Render::operation(
            status: $result->status->value,
            label: $result->label,
            color: $result->status->color(),
            detail: $result->detail === null
                ? null
                : new SpaceSetupValueMasker()->mask($result->detail),
        );
    }
}
