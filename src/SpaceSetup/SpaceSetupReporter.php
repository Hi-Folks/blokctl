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
        private readonly string|null $spaceId = null,
        private readonly SpaceSetupValueMasker $masker = new SpaceSetupValueMasker(),
    ) {}

    public function start(string $spaceId, string $mode): void
    {
        Render::title($this->dryRun ? 'SPACE SETUP PLAN' : 'SPACE SETUP');
        Render::titleSection('Target');
        Render::labelValue('Mode', $this->masker->mask($mode));
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

        Render::notice($this->completionMessage());
        $spaceUrl = $this->spaceUrl();
        if ($spaceUrl !== null) {
            Render::notice($spaceUrl);
        }
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
        return array_any($this->results, fn(\Blokctl\SpaceSetup\SpaceSetupOperationResult $result): bool => $result->status === SpaceSetupOperationStatus::Failed);
    }

    public function completionMessage(): string
    {
        if ($this->dryRun) {
            return 'DRY RUN: No changes were applied.';
        }

        return $this->hasFailures()
            ? 'Space setup completed with failures.'
            : 'Space setup complete.';
    }

    public function spaceUrl(): string|null
    {
        if ($this->dryRun || $this->spaceId === null || $this->spaceId === '') {
            return null;
        }

        return 'https://app.storyblok.com/#/me/spaces/' . $this->spaceId;
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
        $maskedResult = new SpaceSetupOperationResult(
            $result->status,
            $this->masker->mask($result->label),
            $result->detail === null ? null : $this->masker->mask($result->detail),
        );
        $this->results[] = $maskedResult;
        Render::operation(
            status: $maskedResult->status->value,
            label: $maskedResult->label,
            color: $maskedResult->status->color(),
            detail: $maskedResult->detail,
        );
    }
}
