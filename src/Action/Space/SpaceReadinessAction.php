<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

use Storyblok\ManagementApi\Endpoints\SpaceApi;
use Storyblok\ManagementApi\ManagementApiClient;

final readonly class SpaceReadinessAction
{
    private \Closure $clock;

    private \Closure $sleep;

    public function __construct(
        private ManagementApiClient $client,
        \Closure|null $clock = null,
        \Closure|null $sleep = null,
    ) {
        $this->clock = $clock ?? static fn(): float => hrtime(true) / 1_000_000_000;
        $this->sleep = $sleep ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    public function execute(
        string $spaceId,
        int $timeoutSeconds = 120,
        int $pollIntervalSeconds = 2,
    ): SpaceReadinessResult {
        if ($timeoutSeconds < 1) {
            throw new \InvalidArgumentException('Space readiness timeout must be at least 1 second.');
        }

        if ($pollIntervalSeconds < 1) {
            throw new \InvalidArgumentException('Space readiness poll interval must be at least 1 second.');
        }

        $startedAt = ($this->clock)();
        $deadline = $startedAt + $timeoutSeconds;
        $attempts = 0;

        do {
            ++$attempts;
            $space = new SpaceApi($this->client)->get($spaceId)->data();
            $data = $space->toArray();

            if (!array_key_exists('has_pending_tasks', $data)) {
                throw new \RuntimeException(
                    'Unable to determine readiness for duplicated space '
                    . $spaceId
                    . ': the API response does not contain has_pending_tasks.',
                );
            }

            if ($data['has_pending_tasks'] === false) {
                return new SpaceReadinessResult(
                    attempts: $attempts,
                    elapsedSeconds: ($this->clock)() - $startedAt,
                );
            }

            $now = ($this->clock)();
            if ($now >= $deadline) {
                break;
            }

            $remainingMicroseconds = (int) (($deadline - $now) * 1_000_000);
            ($this->sleep)(min($pollIntervalSeconds * 1_000_000, $remainingMicroseconds));
        } while (true);

        throw new \RuntimeException(
            'Duplicated space '
            . $spaceId
            . ' still has pending tasks after '
            . $timeoutSeconds
            . ' seconds.',
        );
    }
}
