<?php

declare(strict_types=1);

namespace Blokctl\Action\Space;

final readonly class SpaceDeleteActivity
{
    /**
     * @param array<string, mixed> $activity
     * @param array<string, mixed>|null $user
     * @param array<string, mixed>|null $trackable
     */
    public function __construct(
        private array $activity,
        private ?array $user = null,
        private ?array $trackable = null,
    ) {}

    public function key(): string
    {
        return $this->stringValue(
            $this->activity['key'] ??
                $this->activity['action'] ??
                $this->activity['event'] ??
                $this->activity['type'] ??
                null,
            'unknown',
        );
    }

    public function trackableType(): string
    {
        return $this->stringValue($this->activity['trackable_type'] ?? null);
    }

    public function createdAt(): string
    {
        return $this->stringValue(
            $this->activity['created_at'] ??
                $this->activity['updated_at'] ??
                $this->activity['created'] ??
                null,
        );
    }

    public function userName(): string
    {
        $owner = $this->arrayValue($this->activity['owner'] ?? null);

        return $this->stringValue(
            $this->user['friendly_name'] ??
                $this->user['name'] ??
                $this->user['email'] ??
                $this->user['userid'] ??
                $owner['friendly_name'] ??
                $owner['name'] ??
                $owner['email'] ??
                null,
        );
    }

    public function targetName(): string
    {
        $trackable = $this->arrayValue($this->activity['trackable'] ?? null);

        return $this->stringValue(
            $this->trackable['name'] ??
                $this->trackable['component_name'] ??
                $this->trackable['asset_name'] ??
                $this->trackable['story_name'] ??
                $this->trackable['slug'] ??
                $trackable['name'] ??
                $trackable['component_name'] ??
                $trackable['asset_name'] ??
                $trackable['story_name'] ??
                $trackable['slug'] ??
                null,
        );
    }

    public function summary(): string
    {
        $parts = [];

        if ($this->trackableType() !== '') {
            $parts[] = '(' . $this->trackableType() . ')';
        }

        if ($this->targetName() !== '') {
            $parts[] = $this->targetName();
        }

        if ($this->userName() !== '') {
            $parts[] = 'by ' . $this->userName();
        }

        if ($this->createdAt() !== '') {
            $parts[] = 'at ' . $this->createdAt();
        }

        return implode(' ', $parts);
    }

    private function stringValue(mixed $value, string $default = ''): string
    {
        if (is_scalar($value)) {
            $value = trim((string) $value);
        }

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
