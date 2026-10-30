<?php

declare(strict_types=1);

namespace Blokctl\Action\Component;

final readonly class ComponentsDiffResult
{
    public const string CHANGED = 'changed';

    public const string ONLY_IN_SOURCE = 'only_in_source';

    public const string ONLY_IN_TARGET = 'only_in_target';

    /**
     * @param list<string> $onlyInSource Component names that exist only in the source space
     * @param list<string> $onlyInTarget Component names that exist only in the target space
     * @param array<string, list<array{path: string, type: string, source: mixed, target: mixed}>> $differences Component name => changes.
     *        Paths start with `settings.`, `fields.<field>`, or `presets.<preset>`
     * @param list<string> $identical Component names that are identical in both spaces
     * @param bool $presetsCompared Whether presets were part of the comparison
     */
    public function __construct(
        public string $sourceSpaceId,
        public string $targetSpaceId,
        public array $onlyInSource,
        public array $onlyInTarget,
        public array $differences,
        public array $identical,
        public bool $presetsCompared,
    ) {}

    public function hasDifferences(): bool
    {
        return $this->onlyInSource !== []
            || $this->onlyInTarget !== []
            || $this->differences !== [];
    }

    public function countCompared(): int
    {
        return count($this->differences) + count($this->identical);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_space_id' => $this->sourceSpaceId,
            'target_space_id' => $this->targetSpaceId,
            'presets_compared' => $this->presetsCompared,
            'has_differences' => $this->hasDifferences(),
            'only_in_source' => $this->onlyInSource,
            'only_in_target' => $this->onlyInTarget,
            'differences' => $this->differences,
            'identical' => $this->identical,
        ];
    }
}
