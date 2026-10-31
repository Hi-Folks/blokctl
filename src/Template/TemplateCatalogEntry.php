<?php

declare(strict_types=1);

namespace Blokctl\Template;

final readonly class TemplateCatalogEntry
{
    /**
     * @param array<string, mixed> $metadata
     * @param list<string> $inputs
     * @param list<string> $extends
     */
    public function __construct(
        public string $path,
        public array $metadata,
        public array $inputs,
        public string $targetMode,
        public array $extends,
    ) {}

    public function name(): string
    {
        $name = $this->metadata['name'] ?? null;

        return is_string($name) ? $name : pathinfo($this->path, PATHINFO_FILENAME);
    }

    /** @return list<string> */
    public function tags(): array
    {
        $tags = $this->metadata['tags'] ?? [];

        return is_array($tags) ? array_values(array_filter($tags, is_string(...))) : [];
    }

    public function description(): string|null
    {
        return $this->stringMetadata('description');
    }

    public function category(): string|null
    {
        return $this->stringMetadata('category');
    }

    public function owner(): string|null
    {
        return $this->stringMetadata('owner');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'name' => $this->name(),
            'description' => $this->description(),
            'category' => $this->category(),
            'owner' => $this->owner(),
            'tags' => $this->tags(),
            'inputs' => $this->inputs,
            'target_mode' => $this->targetMode,
            'extends' => $this->extends,
            'valid' => true,
        ];
    }

    private function stringMetadata(string $key): string|null
    {
        $value = $this->metadata[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
